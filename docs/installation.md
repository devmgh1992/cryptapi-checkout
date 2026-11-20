# Installation

This guide takes a Laravel 10/11/12 application from zero to a working crypto checkout, including the one class that connects the package to your orders.

---

## 1. Requirements

- PHP 8.1+, `ext-json`, `ext-gmp` (used to convert Tron hex addresses to base58 during TXID verification)
- Laravel 10.x, 11.x or 12.x
- A [CryptAPI](https://cryptapi.io) account with the **callback domain whitelisted**
- One payout wallet per network you want to offer

Check the extension:

```bash
php -m | grep -i gmp
```

If it is missing, install it (`apt install php8.3-gmp`, `dnf install php-gmp`, …) or rebuild PHP with `--with-gmp`. Without `gmp`, TRX and USDT-TRC20 transactions cannot be verified.

---

## 2. Install the package

The package is distributed through GitHub, so add it as a VCS repository once per project:

```bash
composer config repositories.cryptapi-checkout vcs https://github.com/devmgh1992/cryptapi-checkout
composer require devmgh1992/cryptapi-checkout
```

Or in `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/devmgh1992/cryptapi-checkout" }
    ],
    "require": {
        "devmgh1992/cryptapi-checkout": "^1.0"
    }
}
```

The service provider is discovered automatically — there is no provider list to edit.

---

## 3. Publish the configuration

```bash
php artisan vendor:publish --tag=cryptapi-checkout-config
```

This creates `config/cryptapi-checkout.php`. Publish the views only if you intend to restyle the page:

```bash
php artisan vendor:publish --tag=cryptapi-checkout-views
```

Published views live in `resources/views/vendor/cryptapi-checkout/` and take precedence over the package copies.

---

## 4. Run the migrations

The migrations are loaded from the package, so a plain `migrate` is enough:

```bash
php artisan migrate
```

Two tables are created:

| Table | Purpose |
|---|---|
| `crypto_checkout_payments` | One row per issued address: coin, amount, status, TXID, payload, expiry |
| `crypto_checkout_sessions` | The active payment session per reference: a rotating key plus its start time |

If you prefer to own the schema, copy the files out of `vendor/devmgh1992/cryptapi-checkout/database/migrations` and remove `loadMigrationsFrom` from the provider in a forked copy — both tables are referenced by name in the models and can be renamed only together with the models.

---

## 5. Configure your wallets

Edit `config/cryptapi-checkout.php` and fill `address` for every network you want to accept. Keep `enabled => false` for the rest — a coin with an empty address is never offered.

```php
'coins' => [
    [
        'ticker'  => 'trc20_usdt',
        'name'    => 'USDT',
        'network' => 'TRON (TRC20)',
        'group'   => 'usdt',
        'address' => env('CRYPTO_USDT_TRC20'),
        'fee_pct' => 5,
        'enabled' => true,
    ],
    [
        'ticker'  => 'trx',
        'name'    => 'TRX',
        'network' => 'TRX',
        'group'   => 'network',
        'address' => env('CRYPTO_TRX'),
        'fee_pct' => 0,
        'enabled' => true,
    ],
],
```

```dotenv
CRYPTO_USDT_TRC20=TYourTronWallet
CRYPTO_TRX=TYourTronWallet
CRYPTO_CHECKOUT_BRAND="Acme"
CRYPTO_CHECKOUT_WINDOW=30
```

`fee_pct` is applied **only** to the network that the customer selects, on top of the order total. A 5% fee on an $18.99 order charges $19.94 on USDT-TRC20 and leaves a 0% network at $18.99.

---

## 6. Implement the resolver

The package knows nothing about your database. One class bridges the gap:

```bash
mkdir -p app/Support
cp vendor/devmgh1992/cryptapi-checkout/stubs/CryptoOrderResolver.stub app/Support/CryptoOrderResolver.php
```

Adapt it to your model:

```php
<?php

namespace App\Support;

use App\Models\Order;
use Nous\CryptApiCheckout\Contracts\OrderResolver;

class CryptoOrderResolver implements OrderResolver
{
    public function find(string $reference): ?array
    {
        $order = Order::where('order_number', $reference)->first();

        if (! $order) {
            return null;
        }

        return [
            'reference'   => $order->order_number,
            'amount'      => (float) $order->total,        // USD, fees are added by the package
            'email'       => $order->email,
            'description' => 'Order ' . $order->order_number,
            'thanks_url'  => route('order.thanks', $order->order_number),
        ];
    }

    public function markPaid(string $reference, array $payment): void
    {
        $order = Order::where('order_number', $reference)->first();

        if (! $order || $order->payment_status === 'paid') {
            return;                                        // idempotent: IPN + TXID can both land
        }

        $order->update([
            'payment_status' => 'paid',
            'payment_method' => 'crypto',
            'transaction_id' => $payment['txid'],
            'paid_at'        => now(),
        ]);

        // provision the service, issue the invoice, notify the customer…
    }
}
```

Point the configuration at it:

```php
'order_resolver' => App\Support\CryptoOrderResolver::class,
```

Returning `null` from `find()` renders a 404 — use it for unknown or cancelled references. Returning an order that is already paid simply redirects the visitor to `thanks_url`.

---

## 7. Link to the checkout

```php
return redirect()->route('cryptapi-checkout.show', $order->order_number);
```

Or in Blade:

```blade
<a href="{{ route('cryptapi-checkout.show', $order->order_number) }}" class="btn">
    Pay with crypto
</a>
```

`?coin=trc20_usdt` preselects a network and skips the picker:

```php
route('cryptapi-checkout.show', ['reference' => $order->order_number, 'coin' => 'trc20_usdt'])
```

---

## 8. Enable the scheduler

The package registers `cryptapi:expire` (every minute). It only runs when Laravel's scheduler is active:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Verify:

```bash
php artisan schedule:list | grep cryptapi
```

Even without the cron, expiry is enforced on every page view and status poll — the command exists so abandoned checkouts are closed promptly.

---

## 9. Whitelist your domain at CryptAPI

Address creation is refused for unknown domains, and the failure surfaces as the fallback "send to our wallet and paste the TXID" screen. In the CryptAPI dashboard add:

- **Callback / IPN domain:** your production host (and `localhost` for development)
- **Address:** the same wallets you configured

---

## 10. Verify the installation

```bash
php artisan route:list | grep cryptapi
php artisan cryptapi:expire
php artisan tinker --execute="dump(app(\Nous\CryptApiCheckout\CryptApi::class)->payableCoins());"
```

Open `/pay/crypto/{some-reference}` and confirm:

1. the network picker lists exactly your enabled coins with their fees,
2. choosing a network shows an address, a QR code, the **coin amount** and a running countdown,
3. `/pay/crypto/{reference}/status` returns `"paid": false`.

A complete end-to-end walkthrough, including a sandbox mode that avoids real addresses, is in [configuration.md](configuration.md#demo-mode).
