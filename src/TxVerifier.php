<?php

namespace Nous\CryptApiCheckout;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a customer-submitted TXID against the real chain before we accept it.
 * Nothing is trusted from the browser: destination wallet, amount, confirmations
 * and block time all come from public chain APIs (TronGrid, EVM JSON-RPC, Solana RPC).
 */
class TxVerifier
{
    /** kept in sync with CryptApi::EVM_RPC */
    private const EVM_RPC = [
        'bep20' => ['https://bsc-dataseed.bnbchain.org', 'https://bsc-rpc.publicnode.com', 'https://binance.llamarpc.com'],
        'polygon' => ['https://polygon-bor-rpc.publicnode.com', 'https://1rpc.io/matic', 'https://polygon.llamarpc.com'],
        'erc20' => ['https://eth.llamarpc.com', 'https://ethereum-rpc.publicnode.com', 'https://cloudflare-eth.com'],
        'eth' => ['https://eth.llamarpc.com', 'https://ethereum-rpc.publicnode.com', 'https://cloudflare-eth.com'],
        'base' => ['https://mainnet.base.org', 'https://base-rpc.publicnode.com'],
        'arbitrum' => ['https://arb1.arbitrum.io/rpc', 'https://arbitrum-one-rpc.publicnode.com'],
        'optimism' => ['https://mainnet.optimism.io', 'https://optimism-rpc.publicnode.com'],
        'avax-c' => ['https://api.avax.network/ext/bc/C/rpc'],
    ];

    /** ticker => [contract as lowercase hex (EVM) or base58 (tron/sol), decimals] */
    private const TOKENS = [
        'bep20_usdt' => ['0x55d398326f99059ff775485246999027b3197955', 18, 'USDT'],
        'polygon_usdt' => ['0xc2132d05d31c914a87c6611c10748aeb04b58e8f', 6, 'USDT'],
        'polygon_usdc' => ['0x2791bca1f2de4661ed88a30c99a7a9449aa84174', 6, 'USDC'],
        'erc20_usdt' => ['0xdac17f958d2ee523a2206206994597c13d831ec7', 6, 'USDT'],
        'erc20_usdc' => ['0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 6, 'USDC'],
        'trc20_usdt' => ['TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 6, 'USDT'],
        'sol_usdt' => ['Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB', 6, 'USDT'],
        'sol_usdc' => ['EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', 6, 'USDC'],
    ];

    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /**
     * @return array{ok: bool, reason?: string, needs_review?: bool, data?: array}
     */
    public function verify(array $coin, string $txid, ?string $expectedTo, ?float $expectedAmount, ?Carbon $notBefore = null): array
    {
        $ticker = strtolower((string) ($coin['ticker'] ?? ''));
        $network = explode('_', $ticker)[0];
        $txid = trim($txid);

        if ($expectedTo === null || $expectedTo === '') {
            return $this->fail('This payment has no destination wallet — please contact support.');
        }

        if (! preg_match('/^[A-Za-z0-9]{40,130}$/', $txid)) {
            return $this->fail('That does not look like a transaction ID.');
        }

        try {
            if ($ticker === 'trx' || $network === 'trc20') {
                return $this->verifyTron($ticker, $txid, $expectedTo, $expectedAmount, $notBefore);
            }

            if (isset(self::EVM_RPC[$network])) {
                return $this->verifyEvm($network, $ticker, $txid, $expectedTo, $expectedAmount, $notBefore);
            }

            if ($network === 'sol') {
                return $this->verifySolana($ticker, $txid, $expectedTo, $expectedAmount, $notBefore);
            }
        } catch (\Throwable $e) {
            Log::warning('TX verification error (' . $ticker . ' ' . $txid . '): ' . $e->getMessage());

            return $this->fail('We could not reach the network right now. Please try again in a minute.');
        }

        return ['ok' => true, 'needs_review' => true, 'data' => ['note' => 'no automatic verifier for ' . $ticker]];
    }

    /* ------------------------------------------------------------------ */
    /* Tron (TRX + TRC20) via TronGrid                                     */
    /* ------------------------------------------------------------------ */
    private function verifyTron(string $ticker, string $txid, string $expectedTo, ?float $expected, ?Carbon $notBefore): array
    {
        $base = 'https://api.trongrid.io';

        $tx = Http::timeout(15)->acceptJson()->post($base . '/wallet/gettransactionbyid', ['value' => $txid])->json();

        if (! is_array($tx) || empty($tx['txID'])) {
            return $this->fail('We could not find that transaction on the Tron network.');
        }

        $ret = (string) ($tx['ret'][0]['contractRet'] ?? 'SUCCESS');

        if ($ret !== 'SUCCESS') {
            return $this->fail('That transaction failed on-chain, so nothing was transferred.');
        }

        $info = Http::timeout(15)->acceptJson()->post($base . '/wallet/gettransactioninfobyid', ['value' => $txid])->json() ?: [];
        $blockNumber = (int) ($info['blockNumber'] ?? $tx['blockNumber'] ?? 0);

        $head = Http::timeout(15)->acceptJson()->post($base . '/wallet/getnowblock', (object) [])->json();
        $headNumber = (int) ($head['block_header']['raw_data']['number'] ?? 0);
        $confirmations = $headNumber > 0 && $blockNumber > 0 ? max(0, $headNumber - $blockNumber) : 0;

        if ($confirmations < 1) {
            return $this->fail('That transaction is not confirmed yet. Wait a moment and submit again.');
        }

        $stamp = (int) ($info['blockTimeStamp'] ?? 0);
        $blockTime = $stamp > 0 ? Carbon::createFromTimestampMs($stamp > 100000000000 ? $stamp : $stamp * 1000) : null;

        $contract = $tx['raw_data']['contract'][0] ?? [];
        $type = (string) ($contract['type'] ?? '');
        $value = $contract['parameter']['value'] ?? [];
        $from = $this->tronAddr((string) ($value['owner_address'] ?? ''));
        $to = null;
        $amount = null;
        $symbol = 'TRX';

        if ($type === 'TransferContract') {
            $to = $this->tronAddr((string) ($value['to_address'] ?? ''));
            $amount = ((int) ($value['amount'] ?? 0)) / 1e6;
        } else {
            // TRC20: read the Transfer event out of the receipt logs
            [$contractAddr, $decimals, $symbol] = self::TOKENS[$ticker] ?? ['', 6, 'USDT'];
            $sum = 0.0;
            $sender = null;

            foreach ((array) ($info['log'] ?? []) as $log) {
                $topics = (array) ($log['topics'] ?? []);

                if (count($topics) < 3 || strcasecmp((string) ($topics[0] ?? ''), self::TRANSFER_TOPIC) !== 0) {
                    continue;
                }

                $logContract = $this->tronAddr((string) ($log['address'] ?? ''));

                if ($contractAddr !== '' && ! hash_equals($contractAddr, $logContract)) {
                    continue;
                }

                $logTo = $this->tronAddr('41' . substr((string) $topics[2], -40));

                if (! hash_equals($expectedTo, $logTo)) {
                    continue;
                }

                $sum += hexdec((string) ($log['data'] ?? '0x0')) / (10 ** $decimals);
                $sender = $this->tronAddr('41' . substr((string) $topics[1], -40));
            }

            if ($sum <= 0) {
                return $this->fail('That transaction never reached our wallet in ' . $symbol . '.');
            }

            $to = $expectedTo;
            $amount = $sum;
            $from = $sender ?: $from;
        }

        $checks = $this->checkCommon($from, $to, $amount, $expectedTo, $expected, $blockTime, $notBefore, $symbol);

        if (! $checks['ok']) {
            return $checks;
        }

        return [
            'ok' => true,
            'data' => [
                'network' => 'tron',
                'symbol' => $symbol,
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'confirmations' => $confirmations,
                'block_time' => $blockTime?->toDateTimeString(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* EVM chains                                                         */
    /* ------------------------------------------------------------------ */
    private function verifyEvm(string $network, string $ticker, string $txid, string $expectedTo, ?float $expected, ?Carbon $notBefore): array
    {
        $tx = $this->rpc($network, 'eth_getTransactionByHash', [$txid]);

        if (! is_array($tx) || empty($tx['hash'])) {
            return $this->fail('We could not find that transaction on this chain.');
        }

        $receipt = $this->rpc($network, 'eth_getTransactionReceipt', [$txid]);

        if (! is_array($receipt) || ($receipt['status'] ?? '0x0') !== '0x1') {
            return $this->fail('That transaction failed or is not confirmed yet.');
        }

        $head = $this->rpc($network, 'eth_blockNumber', []);
        $confirmations = max(0, hexdec((string) $head) - hexdec((string) ($receipt['blockNumber'] ?? '0x0')));

        if ($confirmations < 1) {
            return $this->fail('That transaction is not confirmed yet. Wait a moment and submit again.');
        }

        $block = $this->rpc($network, 'eth_getBlockByNumber', [$receipt['blockNumber'], false]);
        $blockTime = isset($block['timestamp']) ? Carbon::createFromTimestamp(hexdec((string) $block['timestamp'])) : null;

        $from = $tx['from'] ?? null;
        $to = null;
        $amount = null;
        $symbol = strtoupper(explode('_', $ticker)[1] ?? $ticker);

        if (isset(self::TOKENS[$ticker])) {
            [$contract, $decimals, $symbol] = self::TOKENS[$ticker];
            $sum = 0.0;
            $sender = null;

            foreach ((array) ($receipt['logs'] ?? []) as $log) {
                if (strcasecmp((string) ($log['address'] ?? ''), $contract) !== 0) {
                    continue;
                }

                $topics = (array) ($log['topics'] ?? []);

                if (count($topics) < 3 || strcasecmp((string) ($topics[0] ?? ''), self::TRANSFER_TOPIC) !== 0) {
                    continue;
                }

                $logTo = '0x' . substr((string) $topics[2], -40);

                if (! hash_equals(strtolower($expectedTo), strtolower($logTo))) {
                    continue;
                }

                $sum += hexdec((string) ($log['data'] ?? '0x0')) / (10 ** $decimals);
                $sender = '0x' . substr((string) $topics[1], -40);
            }

            if ($sum <= 0) {
                return $this->fail('That transaction never reached our wallet in ' . $symbol . '.');
            }

            $to = $expectedTo;
            $amount = $sum;
            $from = $sender ?: $from;
        } else {
            $to = $tx['to'] ?? null;

            if (! $to || ! hash_equals(strtolower($expectedTo), strtolower((string) $to))) {
                return $this->fail('That transaction was not sent to our wallet.');
            }

            $amount = hexdec((string) ($tx['value'] ?? '0x0')) / 1e18;
        }

        $checks = $this->checkCommon($from, $to, $amount, $expectedTo, $expected, $blockTime, $notBefore, $symbol);

        if (! $checks['ok']) {
            return $checks;
        }

        return [
            'ok' => true,
            'data' => [
                'network' => $network,
                'symbol' => $symbol,
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'confirmations' => $confirmations,
                'block_time' => $blockTime?->toDateTimeString(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Solana                                                             */
    /* ------------------------------------------------------------------ */
    private function verifySolana(string $ticker, string $txid, string $expectedTo, ?float $expected, ?Carbon $notBefore): array
    {
        $body = Http::timeout(20)->acceptJson()->post('https://api.mainnet-beta.solana.com', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'getTransaction',
            'params' => [$txid, ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0, 'commitment' => 'confirmed']],
        ])->json();

        $result = $body['result'] ?? null;

        if (! is_array($result)) {
            return $this->fail('We could not find that transaction on Solana.');
        }

        if (! empty($result['meta']['err'])) {
            return $this->fail('That transaction failed on-chain, so nothing was transferred.');
        }

        $blockTime = isset($result['blockTime']) ? Carbon::createFromTimestamp((int) $result['blockTime']) : null;
        $symbol = self::TOKENS[$ticker][2] ?? 'SOL';
        $amount = null;
        $to = $expectedTo;
        $from = null;

        if (isset(self::TOKENS[$ticker])) {
            $mint = self::TOKENS[$ticker][0];
            $meta = $result['meta'] ?? [];
            $pre = $this->balancesFor((array) ($meta['preTokenBalances'] ?? []), $mint, $expectedTo);
            $post = $this->balancesFor((array) ($meta['postTokenBalances'] ?? []), $mint, $expectedTo);
            $amount = $post - $pre;

            if ($amount <= 0) {
                return $this->fail('We did not see that token arrive in our Solana wallet.');
            }

            $from = $this->solanaSender($result, $expectedTo);
        } else {
            $meta = $result['meta'] ?? [];
            $keys = array_map(fn ($k) => is_array($k) ? ($k['pubkey'] ?? '') : $k, (array) ($result['transaction']['message']['accountKeys'] ?? []));
            $idx = array_search($expectedTo, $keys, true);

            if ($idx === false) {
                return $this->fail('That transaction does not involve our Solana wallet.');
            }

            $pre = (float) ((($meta['preBalances'][$idx] ?? 0)) / 1e9);
            $post = (float) ((($meta['postBalances'][$idx] ?? 0)) / 1e9);
            $amount = $post - $pre;

            if ($amount <= 0) {
                return $this->fail('We did not see SOL arrive in our wallet.');
            }

            $from = $keys[0] ?? null;
        }

        $checks = $this->checkCommon($from, $to, $amount, $expectedTo, $expected, $blockTime, $notBefore, $symbol);

        if (! $checks['ok']) {
            return $checks;
        }

        return [
            'ok' => true,
            'data' => [
                'network' => 'sol',
                'symbol' => $symbol,
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'confirmations' => 1,
                'block_time' => $blockTime?->toDateTimeString(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Shared checks                                                      */
    /* ------------------------------------------------------------------ */
    private function checkCommon(?string $from, ?string $to, ?float $amount, string $expectedTo, ?float $expected, ?Carbon $blockTime, ?Carbon $notBefore, string $symbol): array
    {
        if ($to === null || ! $this->sameAddress($to, $expectedTo)) {
            return $this->fail('That transaction was not sent to our wallet.');
        }

        if ($from && $this->sameAddress($from, $expectedTo)) {
            return $this->fail('That transaction is a transfer out of our own wallet.');
        }

        if ($amount === null || $amount <= 0) {
            return $this->fail('We could not read the amount of that transaction.');
        }

        if ($expected !== null && $expected > 0) {
            $minimum = $expected * 0.99; // tolerate 1% rate/rounding drift

            if ($amount + 1e-9 < $minimum) {
                return $this->fail('That transaction sent ' . $this->fmt($amount) . ' ' . $symbol
                    . ' but ' . $this->fmt($expected) . ' ' . $symbol . ' is required.');
            }
        }

        if ($blockTime === null) {
            return $this->fail('We could not read the time of that transaction — please contact support.');
        }

        if ($blockTime->isFuture() && $blockTime->diffInMinutes(now()) > 10) {
            return $this->fail('That transaction time is in the future.');
        }

        if ($notBefore && $blockTime->lt($notBefore->copy()->subMinutes(15))) {
            return $this->fail('That transaction is older than this invoice, so it cannot be applied to it.');
        }

        if ($blockTime->lt(now()->subDays(3))) {
            return $this->fail('That transaction is more than 3 days old — please contact support.');
        }

        return ['ok' => true];
    }

    private function sameAddress(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);

        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $a) && preg_match('/^0x[0-9a-fA-F]{40}$/', $b)) {
            return hash_equals(strtolower($a), strtolower($b));
        }

        return hash_equals($a, $b);
    }

    /** First endpoint that answers wins. */
    private function rpc(string $network, string $method, array $params): mixed
    {
        foreach (self::EVM_RPC[$network] ?? [] as $url) {
            try {
                $body = Http::timeout(12)->acceptJson()->post($url, [
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
                ])->json();

                if (is_array($body) && array_key_exists('result', $body)) {
                    return $body['result'];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function balancesFor(array $balances, string $mint, string $owner): float
    {
        foreach ($balances as $row) {
            if (strcasecmp((string) ($row['mint'] ?? ''), $mint) === 0 && ($row['owner'] ?? '') === $owner) {
                return (float) ($row['uiTokenAmount']['uiAmount'] ?? 0);
            }
        }

        return 0.0;
    }

    private function solanaSender(array $result, string $owner): ?string
    {
        $keys = array_map(fn ($k) => is_array($k) ? ($k['pubkey'] ?? '') : $k, (array) ($result['transaction']['message']['accountKeys'] ?? []));

        foreach ($keys as $key) {
            if ($key !== '' && $key !== $owner) {
                return $key;
            }
        }

        return null;
    }

    /** Tron addresses arrive either as base58 or as 41-prefixed hex. */
    private function tronAddr(string $address): string
    {
        $address = trim($address);

        if (preg_match('/^41[0-9a-fA-F]{40}$/', $address)) {
            return $this->tronHexToBase58($address);
        }

        return $address;
    }

    private function tronHexToBase58(string $hex): string
    {
        $hex = strtolower(str_replace('0x', '', $hex));
        $raw = hex2bin(substr($hex, -40));
        $check = substr(hash('sha256', hash('sha256', $raw, true), true), 0, 4);

        return $this->base58($raw . $check);
    }

    private function base58(string $bytes): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = gmp_init(bin2hex($bytes), 16);
        $out = '';

        while (gmp_cmp($num, 0) > 0) {
            [$num, $rem] = gmp_div_qr($num, 58);
            $out = $alphabet[gmp_intval($rem)] . $out;
        }

        foreach (str_split($bytes) as $byte) {
            if ($byte !== "\0") {
                break;
            }
            $out = '1' . $out;
        }

        return $out;
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.');
    }

    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
