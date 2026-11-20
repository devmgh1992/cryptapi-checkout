# Integration guide

The package deliberately knows nothing about your domain model. Everything it needs from you fits into one interface:

```php
namespace Nous\CryptApiCheckout\Contracts;

interface OrderResolver
{
    public function find(string $reference): ?array;

    public function markPaid(string $reference, array $payment): void;
}
```

---

## `find()` — how much to charge

Called on every page view, every network switch and every status poll, so it should be a cheap lookup (a primary-key or indexed-column query).

Return `null` to reject the reference (404) — unknown orders, cancelled orders, orders belonging to another user.

```php
public function find(string $reference): ?array
{
    $order = Order::where('order_number', $reference)->first();

    if (! $order || $order->status === 'cancelled') {
        return null;
    }

    return [
        'reference'   => $order->order_number,
        'amount'      => (float) $order->total,     // required, USD
        'email'       => $order->email,             // optional, shown nowhere, useful for logs
        'description' => 'Order ' . $order->order_number, // optional
        'thanks_url'  => route('order.thanks', $order),   // optional, wins over config('thanks_url')
    ];
}
```

| Key | Required | Notes |
|---|---|---|
| `reference` | yes | Echo the requested reference; it is stored on the payment row |
| `amount` | yes | Order total in **USD**. The network fee is added by the package |
| `email` | no | Kept in memory only |
| `description` | no | Free text for your own logs |
| `thanks_url` | no | Absolute URL; overrides the configured `thanks_url` |

### Multi-currency stores

`amount` is always USD. If you sell in another currency, convert before returning:

```php
$rate = Currency::rateFor('USD');            // cached daily rate
'amount' => round($order->total_irr / $rate, 2),
```

### Already-paid orders

Two options — both are safe:

1. return the order as usual; the package sees a `paid` payment and redirects to `thanks_url`;
2. return `null` and redirect the visitor yourself before linking to the checkout.

---

## `markPaid()` — money is in

Called exactly once per payment transition to `paid`, from three places:

| Trigger | Verified beforehand |
|---|---|
| CryptAPI IPN | signed token, `address_in` ownership |
| Manual TXID | destination wallet, amount, confirmations, block time, replay |
| Your own code | `Checkout::markPaid()` — only call it from server-side logic you control |

The payload:

```php
[
    'reference'     => 'ORD-2024-000123',
    'coin'          => 'trc20_usdt',
    'txid'          => '0x…' or Tron/Solana signature (nullable),
    'usd'           => 19.94,        // total charged, fee included
    'coin_amount'   => 19.96,        // exact amount in the coin
    'confirmations' => 19,
    'verified'      => [             // chain facts read by the TXID verifier
        'network' => 'tron',
        'from'    => 'T…',
        'to'      => 'T…',
        'amount'  => 19.96,
        'block_time' => '2024-05-01 10:22:31',
    ],
]
```

### Make it idempotent

Both the IPN and a manual TXID can arrive for the same order. Guard on your own status column:

```php
public function markPaid(string $reference, array $payment): void
{
    $order = Order::where('order_number', $reference)->first();

    if (! $order) {
        return;
    }

    $order->update([
        'payment_status' => 'paid',
        'transaction_id' => $payment['txid'],
        'paid_at'        => $order->paid_at ?? now(),
    ]);

    if ($order->paid_at !== null && $order->wasChanged('paid_at')) {
        return; // already provisioned before this call
    }

    ProvisionOrder::dispatch($order);
}
```

### Prefer events? Both work

```php
Event::listen(CryptoPaymentConfirmed::class, function (CryptoPaymentConfirmed $event) {
    ProvisionOrder::dispatch($event->data['reference']);
});
```

`markPaid()` on the resolver and the `CryptoPaymentConfirmed` event fire back to back. If you use only the event, keep `markPaid()` as a no-op so the interface contract stays honest.

---

## Access control

The checkout is intentionally public: the reference is the capability. Two consequences:

- **Use unguessable references** if your order numbers are sequential. A random suffix (`ORD-8F3K2`) is enough; otherwise add a `signed` middleware or a token column and validate it in `find()`.
- **Never leak amounts you would not charge.** `find()` receives only the reference, so authorise inside it:

```php
public function find(string $reference): ?array
{
    $order = Order::where('order_number', $reference)
        ->where('user_id', auth()->id())          // require the owner
        ->first();

    return $order ? [/* … */] : null;
}
```

If you add auth, also add the matching middleware to the config:

```php
'middleware' => ['web', 'auth'],
```

---

## Localisation

The page copy is English by design and lives in one Blade file. To ship another language, publish the views and replace the strings — the JavaScript strings live in the same file, in `build()` and `showExpired()`.

Numbers, address and coin amounts are rendered with `number_format()` and dot decimals; change them in the view if you need another convention.

---

## Testing your integration

```php
use Nous\CryptApiCheckout\Models\CryptoPayment;

// pretend an IPN confirmed a payment
CryptoPayment::create([
    'reference'     => 'TEST-1',
    'coin'          => 'trc20_usdt',
    'mode'          => 'api',
    'address_in'    => 'TDemoAddress',
    'value_usd'     => 19.94,
    'value_coin'    => 19.96,
    'status'        => 'paid',
    'expires_at'    => now()->addMinutes(30),
]);

// the resolver should have been told
$this->assertTrue(Order::where('order_number', 'TEST-1')->first()->isPaid());
```

For UI work, set `CRYPTO_CHECKOUT_DEMO_ADDRESS` so no real address is requested.
