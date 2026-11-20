# Configuration reference

Everything lives in `config/cryptapi-checkout.php` after publishing. Values marked *env* can be overridden per environment.

---

## Top-level keys

| Key | Type | Default | Description |
|---|---|---|---|
| `prefix` | string | `pay/crypto` | URI prefix for every route (`/pay/crypto/{reference}`) |
| `middleware` | array | `['web']` | Middleware group applied to the checkout routes. Add `throttle:` here if you want extra rate limiting on the page itself |
| `window_minutes` | int | `30` | Payment window. Clamped to 5–240; anything outside is treated as 30 |
| `order_resolver` | class-string | `App\Support\CryptoOrderResolver` | Implements `Nous\CryptApiCheckout\Contracts\OrderResolver` |
| `thanks_url` | string | `/` | Where the customer lands after payment. Supports a `{reference}` placeholder and can be overridden per order by the resolver |
| `secret` | string *env* | `APP_KEY` | HMAC key for IPN tokens. Set `CRYPTO_CHECKOUT_SECRET` to decouple it from `APP_KEY` rotation |
| `fingerprint` | string *env* | `APP_NAME` | Label sent to CryptAPI as the invoice owner (`email` field). Purely informational |
| `brand.name` | string *env* | `config('app.name')` | Text shown in the corner of the checkout page |
| `demo_address` | string *env* | `null` | When set, no address is requested from CryptAPI; every network hands out this address |
| `coins` | array | see below | The catalogue of accepted networks |

---

## Environment variables

| Variable | Maps to |
|---|---|
| `CRYPTO_CHECKOUT_PREFIX` | `prefix` |
| `CRYPTO_CHECKOUT_WINDOW` | `window_minutes` |
| `CRYPTO_CHECKOUT_BRAND` | `brand.name` |
| `CRYPTO_CHECKOUT_SECRET` | `secret` |
| `CRYPTO_CHECKOUT_FINGERPRINT` | `fingerprint` |
| `CRYPTO_CHECKOUT_DEMO_ADDRESS` | `demo_address` |
| `CRYPTO_USDT_TRC20`, `CRYPTO_USDT_BEP20`, `CRYPTO_USDT_SOL`, `CRYPTO_USDT_POLYGON` | coin addresses in the shipped config |
| `CRYPTO_TRX`, `CRYPTO_BTC` | coin addresses in the shipped config |
| `CRYPTO_FEE_TRC20`, `CRYPTO_FEE_BEP20`, … | per-coin `fee_pct` in the shipped config |

The shipped config reads wallets from env so no address is ever committed. Add your own variables if you extend the `coins` array.

---

## The `coins` array

Each entry describes one payable option.

| Field | Required | Description |
|---|---|---|
| `ticker` | yes | `network_token` for tokens (`trc20_usdt`, `bep20_usdt`, `sol_usdt`), or the bare ticker for a native coin (`trx`, `btc`, `eth`). This string is used verbatim in CryptAPI paths (`trc20/usdt`) |
| `name` | yes | Display name (`USDT`, `Bitcoin`, `TRX`) |
| `network` | yes | Chain label shown next to the name (`TRON (TRC20)`, `Solana`, `BTC`) |
| `group` | no | `usdt` (stablecoins), `network` (native coins), `token` (everything else). Controls the picker sections; inferred from the ticker when omitted |
| `address` | yes | Your payout wallet on that network. An empty address disables the coin even if `enabled` is true |
| `fee_pct` | no | Percentage added on top of the order total when this network is chosen. Clamped to 0–50, rounded to 2 decimals |
| `enabled` | no | Default `true`. Set `false` to hide a coin without deleting it |

Rules the package applies:

1. Only coins with `enabled === true` **and** a non-empty `address` are offered.
2. Duplicate tickers are ignored after the first occurrence.
3. If exactly one coin is payable, the picker is skipped and that network is used directly.
4. The fee is calculated as `round(total * (1 + fee_pct / 100), 2)` — per network, never added twice.

### Example: a USDT-only checkout with different fees

```php
'coins' => [
    ['ticker' => 'trc20_usdt',   'name' => 'USDT', 'network' => 'TRON (TRC20)',   'group' => 'usdt',
     'address' => env('W_TRC20'),   'fee_pct' => 5, 'enabled' => true],

    ['ticker' => 'bep20_usdt',   'name' => 'USDT', 'network' => 'BNB Chain (BEP20)', 'group' => 'usdt',
     'address' => env('W_BEP20'),   'fee_pct' => 1, 'enabled' => true],
],
```

### Example: native coins with no fee

```php
'coins' => [
    ['ticker' => 'trx', 'name' => 'TRX',     'network' => 'TRX', 'group' => 'network',
     'address' => env('W_TRX'), 'fee_pct' => 0, 'enabled' => true],

    ['ticker' => 'btc', 'name' => 'Bitcoin', 'network' => 'BTC', 'group' => 'network',
     'address' => env('W_BTC'), 'fee_pct' => 0, 'enabled' => true],
],
```

---

## Payment window

`window_minutes` starts when the **first address of a checkout session** is created. Each session carries a rotating key (`crypto_checkout_sessions.session_key`, copied onto every payment as `session`), so renewal invalidates the previous addresses deterministically — no timestamp comparison involved. Switching between networks does not reset it: the timer belongs to the session, so a customer cannot farm fresh addresses by tapping around.

When the window closes:

1. every unpaid payment of that reference becomes `expired`,
2. the panel turns into an "expired" card with a single action,
3. `POST /pay/crypto/{reference}/renew` starts a new session — same order, same price, new clock and new addresses.

`cryptapi:expire` performs the same transition for abandoned checkouts whose page is no longer open.

---

## Demo mode

```dotenv
CRYPTO_CHECKOUT_DEMO_ADDRESS=TYourTronWallet
```

No HTTP request is made to CryptAPI; `address_in` equals `demo_address` for every coin, and `mode` stays `api` so the timer, polling and TXID form behave normally. This is the recommended way to work on the frontend or to run the package's test suite.

Clear the variable (or set it to `null`) in production — otherwise every customer sees the same address.

---

## Overriding the view

```bash
php artisan vendor:publish --tag=cryptapi-checkout-views
```

The published view is a single, dependency-free Blade file (`checkout.blade.php`) with inline CSS and vanilla JS. Variables available to it:

| Variable | Contents |
|---|---|
| `$reference` | Order reference |
| `$mode` | `select`, `api`, `manual`, `expired`, `unconfigured`, `unknown` |
| `$order` | Whatever `OrderResolver::find()` returned |
| `$payment` | `CryptoPayment` model or `null` |
| `$coin`, `$coins` | Selected coin / payable list |
| `$logos` | Ticker → logo URL map (from CryptAPI) |
| `$coinLabel`, `$coinSymbol`, `$coinLogo` | Display helpers for the selected coin |
| `$coinAmount` | Exact amount in the coin |
| `$baseTotal`, `$feeAmount`, `$amountDue` | USD figures (order total, fee, total charged) |
| `$rate` | Coins per USD at the moment of conversion |
| `$expiresAt`, `$windowMinutes` | ISO timestamp and window length |
| `$qrSvg` | Pre-rendered SVG QR for the current address |
| `$message`, `$error` | Flash messages |

The JavaScript reads everything else from `window.CRYPTO`, which is rendered by the view — keep those keys if you rewrite the markup.
