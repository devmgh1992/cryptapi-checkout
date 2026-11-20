# HTTP API

All endpoints are registered under `config('cryptapi-checkout.prefix')` (default `/pay/crypto`) with the `web` middleware group, so CSRF protection applies to the two `POST` routes.

---

## `GET /{reference}`

Renders the checkout page. Three states are possible:

| State | Rendered |
|---|---|
| No payment yet, several coins | Network picker |
| Payment exists and is alive | Address, QR, amount in coin, countdown |
| Window closed | Expired card with a renew action |

Response headers include `Cache-Control: no-store, max-age=0`.

`GET /{reference}?coin=trc20_usdt` preselects a network. Unknown references return `404`.

---

## `GET /{reference}/coin/{ticker}`

Everything the page needs to swap networks without a reload. Cached client-side per `(reference, ticker)` for 30 minutes.

**200**

```json
{
  "ok": true,
  "mode": "api",
  "notice": null,
  "address": "TJD7MgFAbZHL…",
  "qr_img": null,
  "qr_svg": "<svg …>",
  "value_coin": "19.96",
  "ticker_label": "USDT",
  "rate": 1.0007,
  "coin": {
    "ticker": "trc20_usdt",
    "name": "USDT",
    "network": "TRON (TRC20)",
    "label": "USDT · TRON (TRC20)",
    "logo": "https://api.cryptapi.io/media/token_logos/usdt_trc20.png",
    "fee_pct": 5
  },
  "base": 18.99,
  "fee": 0.95,
  "total": 19.94,
  "can_change": true,
  "expires_at": "2024-05-01T10:30:00+00:00",
  "window_minutes": 30
}
```

| Status | Body | Meaning |
|---|---|---|
| `410` | `{"ok":false,"expired":true,"renew":"…"}` | Window closed; the page shows the expired card |
| `422` | `{"ok":false,"message":"…"}` | Network not configured or disabled |
| `200` | `{"ok":false,"paid":true,"thanks":"…"}` | Already paid; the page redirects |

`mode` is `api` (live address), `manual` (static wallet + TXID form) or `expired`.

---

## `GET /{reference}/status`

Polled by the page every 12 seconds while a payment is open.

```json
{
  "status": "pending",
  "confirmations": 0,
  "paid": false,
  "expired": false,
  "renew": "https://shop.test/pay/crypto/ORD-1/renew",
  "seconds_left": 1794,
  "thanks": "https://shop.test/order/ORD-1/thanks"
}
```

| `status` | Meaning |
|---|---|
| `pending` | Waiting for money |
| `confirming` | Seen on-chain or awaiting manual review |
| `paid` | Confirmed; `thanks` is the redirect target |
| `expired` | Window closed |

`seconds_left` is `null` once paid.

---

## `POST /{reference}/txid`

Submits a transaction hash for verification. Requires a CSRF token and a `txid` field of 32–130 characters (a-zA-Z0-9 only; anything else is stripped).

```http
POST /pay/crypto/ORD-1/txid
Content-Type: application/x-www-form-urlencoded
X-CSRF-TOKEN: …

txid=0x9a1c…
```

| Outcome | Response |
|---|---|
| Verified and confirmed | `302` to `thanks_url`, payment `paid` |
| Chain reachable, TXID invalid | `302` back with `cryptapi_error` in the session |
| Chain unsupported | `302` back with a "manual review" note, payment `confirming` |
| More than 6 attempts in 10 min | `302` back with a rate-limit notice |
| Window closed | `302` back with "this payment window closed" |

Rejections are also written to the payment payload (`last_failure`, `submissions`) and to the log.

---

## `POST /{reference}/renew`

Closes the current session and starts a new one. No body beyond the CSRF token.

```
302 → /pay/crypto/{reference}   (flash: "New payment created — you have 30 minutes")
```

Renewing an already-paid reference redirects to `thanks_url` instead.

---

## `GET /callback/{reference}/{token}`

CryptAPI IPN. Configure this URL as the callback when creating addresses — the package does it for you; you only need to whitelist your domain in the CryptAPI dashboard.

| Query | Used for |
|---|---|
| `address_in` | Locating the payment row (must match) |
| `txid_in` | Stored on confirmation |
| `confirmations` | `>= 1` confirms; `0` moves the row to `confirming` |
| `pending` | `0` counts as confirmed |
| `status` | `confirmed` counts as confirmed |
| `value_coin`, `coin_amount` | Ignored — amounts come from the payment row |

Responses:

| Code | Body | When |
|---|---|---|
| `200` | `ok` | Payment marked paid |
| `200` | `pending` | Acknowledgement, not enough confirmations yet |
| `403` | `forbidden` | Bad or missing token |
| `404` | `unknown payment` | Reference/address/coin combination not found |

CryptAPI treats any non-2xx as a failed delivery and retries — returning `200 pending` is intentional so a not-yet-confirmed notification is not redelivered forever.

---

## Models

```php
use Nous\CryptApiCheckout\Models\CryptoPayment;

CryptoPayment::where('reference', $reference)->latest('id')->first();
```

| Column | Notes |
|---|---|
| `reference` | Your order reference |
| `coin` | Ticker (`trc20_usdt`) |
| `mode` | `api` or `manual` |
| `address_in` | Address shown to the customer |
| `address_out` | Your wallet |
| `qr_code` | Provider-hosted QR image, when returned |
| `value_usd` | Charged total, fee included |
| `value_coin` | Exact amount in the coin |
| `status` | `pending`, `confirming`, `paid`, `expired` |
| `txid`, `confirmations` | Populated on confirmation |
| `payload` | JSON: CryptAPI response, fee breakdown, conversion, verification facts, failed attempts |
| `expires_at`, `paid_at` | Timestamps |
