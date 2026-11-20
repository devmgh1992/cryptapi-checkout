# Troubleshooting

Symptoms, causes and fixes. Enable debug logging first:

```php
// config/logging.php or a temporary tinker session
Log::listen(fn ($log) => logger()->info($log->message));
```

The package logs under `cryptapi-checkout:` prefixes.

---

## The page shows "Crypto payments are not configured yet"

`CryptApi::payableCoins()` returned an empty array. A coin is only payable when **both** conditions hold:

1. `enabled => true`
2. `address` is a non-empty string

```bash
php artisan tinker --execute="dump(app(\Nous\CryptApiCheckout\CryptApi::class)->payableCoins());"
```

If the array is empty, check for a trailing `ENV` variable that is not defined — `env('CRYPTO_USDT_TRC20')` returns `null` when missing, which counts as empty.

Config is cached in production:

```bash
php artisan config:clear   # or config:cache after editing
```

---

## "Send to our wallet and paste the TXID" appears immediately

That is `mode = manual`: CryptAPI refused to create an address. The reason is in the log:

```
cryptapi-checkout: falling back to manual for ORD-…: <provider error>
```

| Provider error | Cause | Fix |
|---|---|---|
| `Non multi_token address already created for another token` | Two coins shared one callback URL | Fixed in 1.0 — each coin is signed separately. If it still appears, a custom resolver is generating the callback manually |
| `Domain not whitelisted` / `Invalid callback` | CryptAPI does not know your host | Whitelist the domain in the CryptAPI dashboard (production **and** `localhost`) |
| `Invalid address` | Wallet address malformed for that chain | Copy it again from the wallet; Tron addresses start with `T`, EVM with `0x` |
| Timeout / 5xx | Provider hiccup | Two attempts are made automatically; the third failure degrades to manual |

---

## Amount shows dollars instead of coins

`value_coin` is `null`, meaning `CryptAPI /convert/` did not return a rate. Check:

```bash
curl "https://api.cryptapi.io/trc20/usdt/convert/?value=19.94&from=USD&to=USDT"
```

- `{"status":"success","value_coin":"19.96"…}` → fine; clear the conversion cache (`cache:clear`, keys are `cryptapi-checkout:convert:*`).
- An error about an unsupported pair → the ticker in your `coins` entry does not match CryptAPI's symbol (e.g. `sol_sol` must be `sol`).

The USD amount is still charged correctly; only the coin display is missing.

---

## Countdown is missing

The timer requires a payment row with `expires_at`:

```php
CryptoPayment::latest('id')->first()->expires_at;
```

If it is `null`, the row was created by an older version or by custom code — re-create the session (`POST /renew`) and it will be stamped.

---

## Page says "This payment window closed" right after renewing

This was a 1.0 bug: the status poll looked at the order-wide newest payment and saw an old `expired` row. Fixed — the poll is scoped to the current session, and `renew` records a session start in `crypto_checkout_sessions`.

If you are on 1.0, upgrade:

```bash
composer update devmgh1992/cryptapi-checkout
php artisan migrate
```

---

## TXID submitted, always rejected with "could not find that transaction"

| Cause | Check |
|---|---|
| Wrong chain | A TRC20 hash pasted while the panel shows BEP20 — the panel only verifies the coin of the current payment |
| Unconfirmed | Tron confirms in ~3 s, BSC in ~3 s, Ethereum up to a minute; the message asks to retry |
| Explorer outage | `gmp` missing disables Tron verification entirely — see below |
| Typo / extra characters | Non-alphanumeric characters are stripped; a hash that is too short fails validation |

Verify Tron support:

```bash
php -m | grep -i gmp
```

No output means Tron TXID verification cannot work: install the extension or verify Tron payments manually.

---

## "That transaction was not sent to our wallet"

The destination address in the transaction does not equal `address` in your config for that coin. This is the check working as intended.

- Did you change the wallet after issuing the address? The payment row keeps the old one; verify against `CryptoPayment::address_out`, not the config.
- For token transfers, the verifier matches the **token contract** and the recipient inside the `Transfer` log — a transfer of a different token to the same address is rejected.

---

## "That transaction ID has already been used for another order"

A `paid` payment row already holds that TXID under a different reference. Look it up:

```php
CryptoPayment::where('txid', $txid)->get(['reference', 'coin', 'paid_at']);
```

If it is a legitimate duplicate (customer paid twice), refund one — the package never credits the same hash twice by design.

---

## Payments are never confirmed by themselves

The IPN is not reaching you. Check, in order:

1. **Domain whitelisted** at CryptAPI (the dashboard has a separate field for callback domains).
2. **Publicly reachable** — CryptAPI cannot call `http://localhost` or an application behind HTTP auth.
3. **Token mismatch** — rotating `APP_KEY` (or `CRYPTO_CHECKOUT_SECRET`) invalidates in-flight tokens; the log shows `bad callback token`.
4. **`address_in` mismatch** — logged as `unknown payment` (404). Happens if you switched wallets mid-flight.
5. **Web server blocking the route** — hit the URL yourself, expecting `403 forbidden` for a bad token:

```bash
curl -i https://your-shop.test/pay/crypto/callback/ORD-1/deadbeef
```

`403` means routing works and only the token is wrong. `404` from nginx means the route is not registered (`route:clear`, `config:clear`) or a rewrite rule is swallowing it.

If the IPN is structurally broken, customers can still pay via the TXID path — the panel switches to `manual` automatically only when address creation fails, so make sure the IPN works in production.

---

## Scheduler complains: "No scheduled commands are ready to run"

`cryptapi:expire` is registered only when the provider boots. Confirm:

```bash
php artisan schedule:list | grep cryptapi
php artisan cryptapi:expire
```

If the command is missing, the package is not discovered: check `bootstrap/cache/packages.php` (run `php artisan package:discover`) or add the provider manually to `bootstrap/providers.php`.

---

## Routes return 404 in a sub-directory deployment

`prefix` is relative to `APP_URL`. Behind a path prefix (`/shop`), set:

```php
'prefix' => 'shop/pay/crypto',
```

and make sure `APP_URL` includes the sub-path so generated callback URLs match.

---

## Nothing helps? Collect this

```bash
php artisan about
php artisan route:list | grep cryptapi
php artisan tinker --execute="dump(config('cryptapi-checkout.coins'));"
tail -n 200 storage/logs/laravel.log | grep cryptapi
```

Redact wallet addresses before pasting them into an issue.
