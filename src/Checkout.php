<?php

namespace Nous\CryptApiCheckout;

use Illuminate\Support\Facades\Log;
use Nous\CryptApiCheckout\Contracts\OrderResolver;
use Nous\CryptApiCheckout\Events\CryptoPaymentConfirmed;
use Nous\CryptApiCheckout\Models\CheckoutSession;
use Nous\CryptApiCheckout\Models\CryptoPayment;

/**
 * Everything that ties an order to a deposit address: reuse, fees, conversion,
 * the payment window and the "money is in" hand-off to the host app.
 */
class Checkout
{
    public function __construct(private CryptApi $api, private TxVerifier $verifier)
    {
    }

    public function resolver(): OrderResolver
    {
        $class = (string) config('cryptapi-checkout.order_resolver');

        if (! $class || ! class_exists($class)) {
            throw new \RuntimeException('cryptapi-checkout.order_resolver is not a valid class.');
        }

        return app($class);
    }

    /**
     * @return array{ok:bool, mode:string, order:?array, payment:?CryptoPayment, coin:?array, coins:array, message:?string}
     *         mode: select | api | manual | expired | unconfigured | unknown | paid
     */
    public function state(string $reference, ?string $ticker = null): array
    {
        $order = $this->resolver()->find($reference);
        $sessionKey = CheckoutSession::where('reference', $reference)->value('session_key');
        $payments = CryptoPayment::where('reference', $reference)->orderByDesc('id')->get();

        if (! $order) {
            return ['ok' => false, 'mode' => 'unknown', 'order' => null, 'payment' => null, 'coin' => null, 'coins' => [], 'message' => 'Order not found.'];
        }

        $paid = $payments->firstWhere('status', 'paid');

        if ($paid) {
            return ['ok' => true, 'mode' => 'paid', 'order' => $order, 'payment' => $paid, 'coin' => $this->api->findCoin((string) $paid->coin), 'coins' => $this->api->payableCoins(), 'message' => null];
        }

        $payable = $this->api->payableCoins();

        if ($payable === []) {
            return ['ok' => false, 'mode' => 'unconfigured', 'order' => $order, 'payment' => null, 'coin' => null, 'coins' => [], 'message' => 'No coin is configured with a wallet address.'];
        }

        // only payments of the *current* session matter; a renewal rotated the key
        $current = $sessionKey
            ? $payments->filter(fn (CryptoPayment $p) => (string) $p->session === (string) $sessionKey)
            : collect();
        $live = $current->first(fn (CryptoPayment $p) => $p->status !== 'expired' && ! $this->api->isExpired($p));

        if (! $live && $current->isNotEmpty()) {
            return ['ok' => false, 'mode' => 'expired', 'order' => $order, 'payment' => $current->first(), 'coin' => null, 'coins' => $payable, 'message' => null];
        }

        $coin = $ticker ? $this->api->findCoin($ticker) : null;

        if ($coin && (! $coin['enabled'] || $coin['address'] === '')) {
            $coin = null;
        }

        if (! $coin) {
            if (count($payable) > 1) {
                return ['ok' => true, 'mode' => 'select', 'order' => $order, 'payment' => null, 'coin' => null, 'coins' => $payable, 'message' => null];
            }

            $coin = $payable[0];
        }

        // reuse a live address for the same coin instead of creating a new one
        if ($live && (string) $live->coin === $coin['ticker'] && $live->address_in) {
            return ['ok' => true, 'mode' => (string) $live->mode, 'order' => $order, 'payment' => $live, 'coin' => $coin, 'coins' => $payable, 'message' => $live->payload['notice'] ?? null];
        }

        return $this->create($order, $coin, $payable, $live);
    }

    private function create(array $order, array $coin, array $payable, ?CryptoPayment $live): array
    {
        $reference = (string) $order['reference'];
        $base = round((float) $order['amount'], 2);
        $value = $this->api->amountFor($base, $coin);
        $fee = $this->api->feeFor($base, $coin);
        $conv = $this->api->convert($coin['ticker'], $value);
        // one clock per checkout session: switching coins never resets the timer
        $expiresAt = $live?->expires_at ?: $this->api->expiresAt();
        $sessionKey = CheckoutSession::current($reference)->session_key;

        $created = $this->api->createAddress($reference, $coin, $value, $this->callbackUrl($reference, $coin['ticker']));

        if (! $created['ok']) {
            Log::warning('cryptapi-checkout: falling back to manual for ' . $reference . ': ' . ($created['error'] ?? ''));

            $payment = CryptoPayment::updateOrCreate(
                ['reference' => $reference, 'coin' => $coin['ticker'], 'address_in' => $coin['address']],
                [
                    'session' => $sessionKey,
                    'mode' => 'manual',
                    'address_out' => $coin['address'],
                    'value_usd' => $value,
                    'value_coin' => $conv['amount'] ?? null,
                    'status' => 'pending',
                    'expires_at' => $expiresAt,
                    'payload' => [
                        'fee' => ['percent' => $coin['fee_pct'], 'amount' => $fee, 'base' => $base],
                        'convert' => $conv,
                        'notice' => 'Live address is unavailable right now. Send the amount to the wallet below and paste the TXID after paying.',
                    ],
                ]
            );

            return ['ok' => true, 'mode' => 'manual', 'order' => $order, 'payment' => $payment, 'coin' => $coin, 'coins' => $payable, 'message' => 'manual'];
        }

        $payment = CryptoPayment::create([
            'reference' => $reference,
            'session' => $sessionKey,
            'coin' => $coin['ticker'],
            'mode' => 'api',
            'address_in' => $created['address'],
            'address_out' => $created['address_out'] ?? $coin['address'],
            'qr_code' => $created['qr_code'] ?? null,
            'value_usd' => $value,
            'value_coin' => $conv['amount'] ?? null,
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'payload' => [
                'cryptapi' => $created['payload'] ?? [],
                'convert' => $conv,
                'fee' => ['percent' => $coin['fee_pct'], 'amount' => $fee, 'base' => $base],
            ],
        ]);

        return ['ok' => true, 'mode' => 'api', 'order' => $order, 'payment' => $payment, 'coin' => $coin, 'coins' => $payable, 'message' => null];
    }

    /** Mark a payment paid and let the host provision the order. */
    public function markPaid(CryptoPayment $payment, ?string $txid, int $confirmations = 1, array $verified = []): void
    {
        $payment->update([
            'status' => 'paid',
            'txid' => $txid ?: $payment->txid,
            'confirmations' => $confirmations,
            'paid_at' => now(),
            'payload' => array_merge((array) $payment->payload, ['verified' => $verified]),
        ]);

        $data = [
            'reference' => (string) $payment->reference,
            'coin' => (string) $payment->coin,
            'txid' => $txid,
            'usd' => (float) $payment->value_usd,
            'coin_amount' => $payment->value_coin !== null ? (float) $payment->value_coin : null,
            'confirmations' => $confirmations,
            'verified' => $verified,
        ];

        $this->resolver()->markPaid((string) $payment->reference, $data);

        event(new CryptoPaymentConfirmed($payment, $data));
    }

    /** Start a fresh payment window for this reference (after an expiry). */
    public function advanceSession(string $reference): void
    {
        CheckoutSession::current($reference)->rotate();
    }

    public function callbackUrl(string $reference, string $ticker): string
    {
        return route('cryptapi-checkout.callback', [
            'reference' => $reference,
            'token' => $this->sign($reference, $ticker) . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
        ]);
    }

    public function sign(string $reference, ?string $ticker = null): string
    {
        $secret = (string) (config('cryptapi-checkout.secret') ?: config('app.key'));
        $suffix = $ticker ? '|' . $this->api->normalizeTicker($ticker) : '';

        return substr(hash_hmac('sha256', $reference . $suffix, $secret), 0, 24);
    }

    public function tokenMatches(string $reference, string $token): ?string
    {
        $head = explode('-', $token)[0] ?? '';

        foreach ($this->api->coins() as $coin) {
            if (hash_equals($this->sign($reference, $coin['ticker']), $head)) {
                return $coin['ticker'];
            }
        }

        return hash_equals($this->sign($reference), $head) ? '' : null;
    }

    public function thanksUrl(string $reference): string
    {
        $template = (string) config('cryptapi-checkout.thanks_url', '/');
        $order = $this->resolver()->find($reference);

        if (! empty($order['thanks_url'])) {
            return (string) $order['thanks_url'];
        }

        return str_contains($template, '{reference}')
            ? str_replace('{reference}', $reference, $template)
            : $template;
    }
}
