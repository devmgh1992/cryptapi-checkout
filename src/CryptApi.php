<?php

namespace Nous\CryptApiCheckout;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nous\CryptApiCheckout\Models\CryptoPayment;

/**
 * Thin client for CryptAPI + the domain rules of this checkout
 * (fee per coin, USD -> coin conversion, payment window).
 */
class CryptApi
{
    public const BASE = 'https://api.cryptapi.io';

    /** network prefix => list of public RPC endpoints (verifier uses them) */
    public const EVM_RPC = [
        'bep20' => ['https://bsc-dataseed.bnbchain.org', 'https://bsc-rpc.publicnode.com'],
        'polygon' => ['https://polygon-bor-rpc.publicnode.com', 'https://1rpc.io/matic'],
        'erc20' => ['https://eth.llamarpc.com', 'https://ethereum-rpc.publicnode.com'],
        'eth' => ['https://eth.llamarpc.com', 'https://ethereum-rpc.publicnode.com'],
        'base' => ['https://mainnet.base.org', 'https://base-rpc.publicnode.com'],
        'arbitrum' => ['https://arb1.arbitrum.io/rpc', 'https://arbitrum-one-rpc.publicnode.com'],
        'optimism' => ['https://mainnet.optimism.io', 'https://optimism-rpc.publicnode.com'],
        'avax-c' => ['https://api.avax.network/ext/bc/C/rpc'],
    ];

    /** ticker => [contract (lowercase hex or base58), decimals, symbol] */
    public const TOKENS = [
        'bep20_usdt' => ['0x55d398326f99059ff775485246999027b3197955', 18, 'USDT'],
        'polygon_usdt' => ['0xc2132d05d31c914a87c6611c10748aeb04b58e8f', 6, 'USDT'],
        'polygon_usdc' => ['0x2791bca1f2de4661ed88a30c99a7a9449aa84174', 6, 'USDC'],
        'erc20_usdt' => ['0xdac17f958d2ee523a2206206994597c13d831ec7', 6, 'USDT'],
        'erc20_usdc' => ['0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 6, 'USDC'],
        'trc20_usdt' => ['TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 6, 'USDT'],
        'sol_usdt' => ['Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB', 6, 'USDT'],
        'sol_usdc' => ['EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', 6, 'USDC'],
    ];

    /* ------------------------------------------------------------------ */
    /* Coins                                                              */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array{ticker:string,name:string,network:string,group:string,address:string,fee_pct:float,enabled:bool}> */
    public function coins(): array
    {
        $rows = [];

        foreach ((array) config('cryptapi-checkout.coins', []) as $row) {
            if (! is_array($row) || empty($row['ticker'])) {
                continue;
            }

            $ticker = $this->normalizeTicker((string) $row['ticker']);

            if (isset($rows[$ticker])) {
                continue;
            }

            $rows[$ticker] = [
                'ticker' => $ticker,
                'name' => (string) ($row['name'] ?? strtoupper($ticker)),
                'network' => (string) ($row['network'] ?? strtoupper(explode('_', $ticker)[0])),
                'group' => (string) ($row['group'] ?? $this->groupFor($ticker)),
                'address' => trim((string) ($row['address'] ?? '')),
                'fee_pct' => round(max(0, min(50, (float) ($row['fee_pct'] ?? 0))), 2),
                'enabled' => (bool) ($row['enabled'] ?? true),
            ];
        }

        return array_values($rows);
    }

    /** @return array<int, array> */
    public function payableCoins(): array
    {
        return array_values(array_filter($this->coins(), fn ($c) => $c['enabled'] && $c['address'] !== ''));
    }

    public function findCoin(string $ticker): ?array
    {
        $ticker = $this->normalizeTicker($ticker);

        foreach ($this->coins() as $coin) {
            if ($coin['ticker'] === $ticker) {
                return $coin;
            }
        }

        return null;
    }

    public function normalizeTicker(string $ticker): string
    {
        $ticker = strtolower(trim($ticker));
        $ticker = str_replace(['/', ' '], '_', $ticker);
        $ticker = preg_replace('/[^a-z0-9._\-]/', '', $ticker) ?? '';

        return $ticker === 'trc20' ? 'trc20_usdt' : $ticker;
    }

    public function pathFor(string $ticker): string
    {
        return str_replace('_', '/', $this->normalizeTicker($ticker));
    }

    public function groupFor(string $ticker): string
    {
        $ticker = $this->normalizeTicker($ticker);

        if (str_ends_with($ticker, '_usdt') || str_ends_with($ticker, '_usdc')) {
            return 'usdt';
        }

        return str_contains($ticker, '_') ? 'token' : 'network';
    }

    public function symbolFor(string $ticker): string
    {
        $parts = explode('_', $this->normalizeTicker($ticker));

        return strtoupper((string) end($parts));
    }

    /** "USDT · TRON (TRC20)" / "TRX" / "SOL · Solana" */
    public function labelFor(array $coin): string
    {
        $name = trim((string) $coin['name']);
        $network = trim((string) $coin['network']);

        if ($coin['group'] === 'usdt') {
            return 'USDT · ' . $network;
        }

        if ($coin['group'] === 'network' || $network === '') {
            return $name;
        }

        if (strcasecmp($network, $name) === 0) {
            return $this->symbolFor($coin['ticker']) . ' · ' . $network;
        }

        return $name . ' · ' . $network;
    }

    public function amountFor(float $base, array $coin): float
    {
        return round($base * (1 + ((float) $coin['fee_pct']) / 100), 2);
    }

    public function feeFor(float $base, array $coin): float
    {
        return round($this->amountFor($base, $coin) - $base, 2);
    }

    /* ------------------------------------------------------------------ */
    /* CryptAPI calls                                                     */
    /* ------------------------------------------------------------------ */

    public function catalog(): array
    {
        return Cache::remember('cryptapi-checkout:catalog', now()->addHours(12), function () {
            $items = [];

            try {
                $data = Http::timeout(10)->acceptJson()->get(self::BASE . '/info')->json();
            } catch (\Throwable $e) {
                Log::warning('CryptAPI catalogue failed: ' . $e->getMessage());
                $data = [];
            }

            foreach ((array) $data as $network => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if (isset($entry['coin'], $entry['ticker'])) {
                    $ticker = $this->normalizeTicker((string) $entry['ticker']);
                    $items[$ticker] = [
                        'ticker' => $ticker,
                        'name' => (string) $entry['coin'],
                        'network' => strtoupper($ticker),
                        'group' => 'network',
                        'logo' => $entry['logo'] ?? null,
                    ];

                    continue;
                }

                foreach ($entry as $meta) {
                    if (! is_array($meta) || empty($meta['ticker'])) {
                        continue;
                    }

                    $ticker = $this->normalizeTicker($network . '_' . $meta['ticker']);
                    $items[$ticker] = [
                        'ticker' => $ticker,
                        'name' => (string) ($meta['coin'] ?? strtoupper((string) $meta['ticker'])),
                        'network' => (string) ($meta['name'] ?? strtoupper((string) $network)),
                        'group' => $this->groupFor($ticker),
                        'logo' => $meta['logo'] ?? null,
                    ];
                }
            }

            return array_values($items);
        });
    }

    public function logos(): array
    {
        $map = [];

        foreach ($this->catalog() as $item) {
            if (! empty($item['logo'])) {
                $map[$item['ticker']] = (string) $item['logo'];
            }
        }

        return $map;
    }

    /** How much of the coin the customer must send for a USD total. */
    public function convert(string $ticker, float $usd): ?array
    {
        $path = $this->pathFor($ticker);
        $symbol = $this->symbolFor($ticker);
        $key = 'cryptapi-checkout:convert:' . md5($path . '|' . number_format($usd, 2, '.', ''));

        return Cache::remember($key, now()->addSeconds(90), function () use ($path, $symbol, $usd) {
            try {
                $body = Http::timeout(12)->acceptJson()->get(self::BASE . '/' . $path . '/convert/', [
                    'value' => number_format($usd, 2, '.', ''),
                    'from' => 'USD',
                    'to' => $symbol,
                ])->json();
            } catch (\Throwable $e) {
                Log::warning('CryptAPI convert failed: ' . $e->getMessage());

                return null;
            }

            if (($body['status'] ?? '') !== 'success' || empty($body['value_coin'])) {
                return null;
            }

            return ['amount' => (float) $body['value_coin'], 'rate' => (float) ($body['exchange_rate'] ?? 0)];
        });
    }

    /**
     * Ask CryptAPI for a one-off deposit address. Each coin gets its own callback
     * URL, because CryptAPI allows only one invoice per callback.
     *
     * @return array{ok:bool,address?:string,address_out?:string,qr_code?:?string,error?:?string,payload?:array}
     */
    public function createAddress(string $reference, array $coin, float $valueUsd, string $callbackUrl): array
    {
        if ($demo = config('cryptapi-checkout.demo_address')) {
            return ['ok' => true, 'address' => (string) $demo, 'address_out' => $coin['address'], 'qr_code' => null, 'payload' => ['demo' => true]];
        }

        $body = [];
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::timeout(20)->acceptJson()->get(self::BASE . '/' . $this->pathFor($coin['ticker']) . '/create/', [
                    'callback' => $callbackUrl,
                    'address' => $coin['address'],
                    'pending' => 1,
                    'value' => number_format($valueUsd, 2, '.', ''),
                    'currency' => 'USD',
                    'email' => (string) config('cryptapi-checkout.fingerprint', config('app.name')),
                ]);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Crypto provider is unreachable right now.'];
            }

            $body = $response->json() ?: [];

            if ($response->successful() && ($body['status'] ?? '') === 'success' && ! empty($body['address_in'])) {
                return [
                    'ok' => true,
                    'address' => (string) $body['address_in'],
                    'address_out' => (string) ($body['address_out'] ?? $coin['address']),
                    'qr_code' => $body['qr_code'] ?? null,
                    'payload' => $body,
                ];
            }

            $lastError = is_string($body['error'] ?? null) ? $body['error'] : json_encode($body['error'] ?? null);
            Log::warning('CryptAPI create failed (attempt ' . $attempt . '): ' . $lastError);

            if ($attempt === 2 || stripos((string) $lastError, 'multi_token') === false) {
                break;
            }

            usleep(250000);
        }

        return ['ok' => false, 'error' => $lastError ?: 'Crypto provider did not return an address.'];
    }

    /* ------------------------------------------------------------------ */
    /* Payment window                                                     */
    /* ------------------------------------------------------------------ */

    public function windowMinutes(): int
    {
        $minutes = (int) config('cryptapi-checkout.window_minutes', 30);

        return $minutes >= 5 && $minutes <= 240 ? $minutes : 30;
    }

    public function isExpired(?CryptoPayment $payment): bool
    {
        return $payment !== null
            && $payment->status !== 'paid'
            && $payment->expires_at !== null
            && now()->greaterThan($payment->expires_at);
    }

    /** Cancel every unpaid payment whose window closed. */
    public function sweepExpired(): int
    {
        $expired = CryptoPayment::whereIn('status', ['pending', 'confirming'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $payment) {
            $payment->update(['status' => 'expired']);
            event(new Events\CryptoPaymentExpired($payment));
        }

        return $expired->count();
    }

    public function expiresAt(): Carbon
    {
        return now()->addMinutes($this->windowMinutes());
    }
}
