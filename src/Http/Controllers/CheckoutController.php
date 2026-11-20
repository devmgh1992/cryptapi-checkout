<?php

namespace Nous\CryptApiCheckout\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nous\CryptApiCheckout\Checkout;
use Nous\CryptApiCheckout\CryptApi;
use Nous\CryptApiCheckout\Events\CryptoPaymentExpired;
use Nous\CryptApiCheckout\Models\CryptoPayment;
use Nous\CryptApiCheckout\QrCode;
use Nous\CryptApiCheckout\TxVerifier;

class CheckoutController extends Controller
{
    public function __construct(private Checkout $checkout, private CryptApi $api)
    {
    }

    public function show(Request $request, string $reference)
    {
        $this->api->sweepExpired();
        $state = $this->checkout->state($reference, $request->query('coin') ? (string) $request->query('coin') : null);

        if ($state['mode'] === 'paid') {
            return redirect()->away($this->checkout->thanksUrl($reference));
        }

        if ($state['mode'] === 'unknown') {
            abort(404);
        }

        $coins = $state['coins'];
        $payment = $state['payment'];
        $coin = $state['coin'];
        $logos = [];

        foreach ($this->api->logos() as $ticker => $logo) {
            $logos[$ticker] = $logo;
        }

        $base = round((float) ($state['order']['amount'] ?? 0), 2);

        return response()
            ->view('cryptapi-checkout::checkout', [
                'reference' => $reference,
                'mode' => $state['mode'],
                'order' => $state['order'],
                'payment' => $payment,
                'coin' => $coin,
                'coins' => $coins,
                'logos' => $logos,
                'coinLabel' => $coin ? $this->api->labelFor($coin) : null,
                'coinSymbol' => $coin ? $this->api->symbolFor($coin['ticker']) : null,
                'coinLogo' => $coin ? ($logos[$coin['ticker']] ?? null) : null,
                'coinAmount' => $payment?->value_coin,
                'rate' => $payment?->payload['convert']['rate'] ?? null,
                'baseTotal' => $base,
                'feeAmount' => $coin ? $this->api->feeFor($base, $coin) : 0.0,
                'amountDue' => $coin ? $this->api->amountFor($base, $coin) : $base,
                'expiresAt' => $payment?->expires_at?->toIso8601String(),
                'windowMinutes' => $this->api->windowMinutes(),
                'qrSvg' => $payment && $payment->address_in ? QrCode::svg($payment->address_in) : null,
                'message' => session('cryptapi_message'),
                'error' => session('cryptapi_error'),
            ])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /** JSON for in-page network switching (cached client-side per coin). */
    public function coin(string $reference, string $ticker)
    {
        $state = $this->checkout->state($reference, $ticker);

        if ($state['mode'] === 'paid') {
            return response()->json(['ok' => false, 'paid' => true, 'thanks' => $this->checkout->thanksUrl($reference)]);
        }

        if ($state['mode'] === 'expired') {
            return response()->json([
                'ok' => false,
                'expired' => true,
                'renew' => route('cryptapi-checkout.renew', $reference),
                'message' => 'This payment window ran out. Start a new one to pay.',
            ], 410);
        }

        if ($state['mode'] === 'unknown' || ! $state['coin']) {
            return response()->json(['ok' => false, 'message' => $state['message'] ?: 'This network is not available.'], 422);
        }

        $payment = $state['payment'];
        $coin = $state['coin'];
        $logo = $this->api->logos()[$coin['ticker']] ?? null;
        $base = round((float) $state['order']['amount'], 2);

        return response()->json([
            'ok' => true,
            'mode' => $state['mode'],
            'notice' => $state['message'],
            'address' => $payment?->address_in,
            'qr_img' => $payment?->qr_code,
            'qr_svg' => $payment && $payment->address_in ? QrCode::svg($payment->address_in) : null,
            'value_coin' => $payment?->value_coin !== null ? rtrim(rtrim(number_format((float) $payment->value_coin, 8, '.', ''), '0'), '.') : null,
            'ticker_label' => $this->api->symbolFor($coin['ticker']),
            'rate' => $payment?->payload['convert']['rate'] ?? null,
            'coin' => [
                'ticker' => $coin['ticker'],
                'name' => $coin['name'],
                'network' => $coin['network'],
                'label' => $this->api->labelFor($coin),
                'logo' => $logo,
                'fee_pct' => (float) $coin['fee_pct'],
            ],
            'base' => $base,
            'fee' => $this->api->feeFor($base, $coin),
            'total' => $this->api->amountFor($base, $coin),
            'can_change' => count($state['coins']) > 1,
            'expires_at' => $payment?->expires_at?->toIso8601String(),
            'window_minutes' => $this->api->windowMinutes(),
        ]);
    }

    public function status(string $reference)
    {
        $payments = CryptoPayment::where('reference', $reference)->get();
        $payment = $payments->firstWhere('status', 'paid') ?: $payments->sortByDesc('id')->first();
        $paid = (bool) $payments->firstWhere('status', 'paid');
        $expired = ! $paid && $payment !== null && ($payment->status === 'expired' || $this->api->isExpired($payment));

        if ($expired && $payment->status !== 'expired') {
            $payment->update(['status' => 'expired']);
            $payment->refresh();
            event(new CryptoPaymentExpired($payment));
        }

        return response()->json([
            'status' => $payment->status ?? 'pending',
            'confirmations' => (int) ($payment->confirmations ?? 0),
            'paid' => $paid,
            'expired' => $expired,
            'renew' => route('cryptapi-checkout.renew', $reference),
            'seconds_left' => ! $paid && $payment?->expires_at ? max(0, now()->diffInSeconds($payment->expires_at, false)) : null,
            'thanks' => $this->checkout->thanksUrl($reference),
        ]);
    }

    /** Manual TXID entry: verified on-chain before anything is activated. */
    public function txid(Request $request, string $reference)
    {
        $data = $request->validate(['txid' => 'required|string|min:32|max:130']);
        $txid = preg_replace('/[^A-Za-z0-9]/', '', (string) $data['txid']) ?? '';

        $payments = CryptoPayment::where('reference', $reference)->orderByDesc('id')->get();
        $payment = $payments->first();

        if (! $payment) {
            return back()->with('cryptapi_error', 'Pick a network first, then submit the transaction ID.');
        }

        if ($payments->firstWhere('status', 'paid')) {
            return redirect()->away($this->checkout->thanksUrl($reference));
        }

        $key = 'cryptapi-checkout:txid:' . $reference;

        if ((int) Cache::get($key, 0) >= 6) {
            return back()->with('cryptapi_error', 'Too many attempts. Please try again in 10 minutes.');
        }

        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addMinutes(10));

        if ($this->api->isExpired($payment)) {
            $payment->update(['status' => 'expired']);

            return back()->with('cryptapi_error', 'This payment window closed, so that transaction cannot be applied. Start a new payment.');
        }

        $replay = CryptoPayment::where('txid', $txid)
            ->where('reference', '!=', $reference)
            ->where('status', 'paid')
            ->exists();

        if ($replay) {
            Log::warning('cryptapi-checkout: TXID replay for ' . $reference . ': ' . $txid);

            return back()->with('cryptapi_error', 'That transaction ID has already been used for another order.');
        }

        $coin = $this->api->findCoin((string) $payment->coin);

        if (! $coin) {
            return back()->with('cryptapi_error', 'This coin is no longer configured.');
        }

        $result = app(TxVerifier::class)->verify(
            $coin,
            $txid,
            (string) $payment->address_in,
            $payment->value_coin !== null ? (float) $payment->value_coin : null,
            $payment->created_at
        );

        $payload = (array) $payment->payload;
        $payload['submissions'] = array_slice(array_merge((array) ($payload['submissions'] ?? []), [[
            'txid' => $txid,
            'at' => now()->toDateTimeString(),
        ]]), -10);

        if (! ($result['ok'] ?? false)) {
            $payload['last_failure'] = ['txid' => $txid, 'reason' => $result['reason'] ?? null, 'at' => now()->toDateTimeString()];
            $payment->update(['payload' => $payload]);

            return back()->with('cryptapi_error', $result['reason'] ?? 'We could not verify that transaction.');
        }

        $verified = (array) ($result['data'] ?? []);
        $payload['verified'] = $verified;
        $payment->update(['payload' => $payload, 'txid' => $txid, 'confirmations' => (int) ($verified['confirmations'] ?? 1)]);

        if (! empty($result['needs_review'])) {
            $payment->update(['status' => 'confirming']);

            return back()->with('cryptapi_message', 'We received the transaction ID — it is checked manually before activation.');
        }

        $this->checkout->markPaid($payment, $txid, (int) ($verified['confirmations'] ?? 1), $verified);

        return redirect()->away($this->checkout->thanksUrl($reference));
    }

    public function renew(string $reference)
    {
        CryptoPayment::where('reference', $reference)
            ->whereIn('status', ['pending', 'confirming'])
            ->update(['status' => 'expired']);

        $this->checkout->advanceSession($reference);

        return redirect()
            ->route('cryptapi-checkout.show', $reference)
            ->with('cryptapi_message', 'New payment created — you have ' . $this->api->windowMinutes() . ' minutes to pay it.');
    }

    /** CryptAPI IPN. */
    public function callback(Request $request, string $reference, string $token)
    {
        $ticker = $this->checkout->tokenMatches($reference, $token);

        if ($ticker === null) {
            Log::warning('cryptapi-checkout: bad callback token for ' . $reference);

            return response('forbidden', 403);
        }

        $addressIn = (string) $request->query('address_in', '');
        $payment = CryptoPayment::where('reference', $reference)
            ->when($addressIn !== '', fn ($q) => $q->where('address_in', $addressIn))
            ->when($ticker !== '', fn ($q) => $q->where('coin', $ticker))
            ->orderByDesc('id')
            ->first();

        if (! $payment) {
            return response('unknown payment', 404);
        }

        $confirmations = (int) $request->query('confirmations', 0);
        $confirmed = $request->query('pending') === '0'
            || $confirmations >= 1
            || $request->query('status') === 'confirmed';

        if (! $confirmed) {
            $payment->update([
                'status' => 'confirming',
                'confirmations' => $confirmations,
                'txid' => $request->query('txid_in') ?: $payment->txid,
            ]);

            return response('pending');
        }

        $this->checkout->markPaid($payment, $request->query('txid_in'), $confirmations, $request->query());

        return response('ok');
    }
}
