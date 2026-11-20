# Security model

Crypto payments fail in one of two ways: someone is credited without paying, or a real payment is credited twice. This document describes how the package prevents both, and what is left for you to do.

---

## Trust boundaries

```
browser ──► HTTP endpoints ──► Checkout service ──► CryptAPI (addresses, conversion)
                                     │
                                     └──► chain APIs (TronGrid, EVM RPC, Solana RPC)
```

The browser is treated as **untrusted input**. It can ask for a network, ask for a status, or submit a TXID. It cannot assert anything: every fact used for activation is fetched server-side.

---

## Confirmation paths

### 1. CryptAPI IPN (primary)

```
GET /pay/crypto/callback/{reference}/{token}?address_in=…&txid_in=…&confirmations=…
```

| Check | Implementation |
|---|---|
| Token authenticity | `token = HMAC-SHA256(reference[|ticker], secret)[0:24]` + a random nonce suffix. Verified with `hash_equals()` |
| Per-coin binding | Each network is signed with its own ticker, so a token minted for `trc20_usdt` cannot confirm a `trx` payment |
| Payment ownership | The `address_in` query parameter must match a payment issued for that reference (and coin) |
| Confirmation state | `pending=0`, `confirmations >= 1`, or `status=confirmed` — otherwise the row is only moved to `confirming` |
| Replay | A paid row is left untouched; `markPaid()` is idempotent on your side too |

A callback that fails the token check returns `403` and is logged with the reference.

### 2. Manual TXID (fallback)

Shown only when CryptAPI cannot issue an address (`mode = manual`) — the customer sends to your static wallet and pastes the transaction hash. The hash is then verified against the chain by `TxVerifier`:

| Check | Detail |
|---|---|
| Existence | The transaction must be known to the network |
| On-chain status | Reverted/failed transactions are rejected (`ret != SUCCESS`, `receipt.status != 0x1`, `meta.err != null`) |
| Confirmations | At least 1; otherwise the customer is asked to retry |
| Destination | Must equal the payout wallet configured for that coin |
| Amount | Must be ≥ the required coin amount, with 1% tolerance for rate drift |
| Age | Block time must not precede the payment window by more than 15 minutes, must not be in the future, and must not be older than 3 days |
| Self-transfer | Transactions sent **from** your payout wallet are rejected |
| Replay | A TXID already `paid` on another reference is rejected and logged |
| Brute force | 6 submissions per 10 minutes per reference (cache-backed) |
| Expiry | Submissions for an expired window are refused outright |

Payloads of rejected attempts are stored in the payment row (`payload.last_failure`, `payload.submissions`) so you can audit abuse later.

### 3. Unsupported chains

If no verifier exists for a network, the result carries `needs_review` and the payment moves to `confirming` — **never** to `paid`. Your team confirms it manually, then calls `Checkout::markPaid()` from an admin action or marks the order paid in your own panel.

---

## Address creation

Each `POST /{network}/create` call uses a callback URL signed for that reference **and** that coin, plus a random nonce. CryptAPI allows a single invoice per callback URL, so per-coin signing is what makes network switching work; the nonce guarantees that a retry after a provider error gets a fresh invoice instead of a `multi_token` rejection.

Up to two attempts are made on provider failure; after that the checkout degrades to `manual` mode with a visible notice instead of showing a broken address.

---

## Payment window

The window belongs to the checkout session, not to a single address:

- starting a session stamps `expires_at = now + window_minutes`;
- switching networks reuses that stamp — the timer never resets;
- when it closes, unpaid rows become `expired` and late claims are refused;
- `POST /renew` rotates the session key (`crypto_checkout_sessions.session_key`); payments keep the key they were created with, so the previous addresses are ignored while the order stays payable.

Expiry is enforced in three layers: the scheduled command, every page view, and every status poll. A customer leaving a tab open cannot outlive the window; a missing cron cannot leave an address monitored forever.

---

## Hardening checklist for your deployment

- [ ] `APP_KEY` (or `CRYPTO_CHECKOUT_SECRET`) is set and secret. Rotating it invalidates in-flight IPN tokens — finish open checkouts first.
- [ ] Production runs over HTTPS; the page is sent with `Cache-Control: no-store`.
- [ ] Only wallets you control are configured. A typo in `address` sends customer funds to a stranger.
- [ ] `demo_address` is empty in production.
- [ ] The Laravel scheduler runs (`php artisan schedule:list`).
- [ ] Your resolver authorises the reference (see [integration.md](integration.md#access-control)).
- [ ] Logs for `cryptapi-checkout` are retained — IPN rejections and TXID failures are logged with `Log::warning`.
- [ ] Order amounts are immutable while a checkout is open: the amount is read from `find()` on every request, so editing the order mid-flight changes what is charged. Lock paid/pending totals in your own model if that matters.
- [ ] Rate limiting on the checkout page itself if you expect abuse: add `throttle:30,1` to the `middleware` array.

---

## What this package does **not** do

- It does not custody funds: payments go straight to the wallet you configure.
- It does not re-price an order after it expires — a renewed window charges the current order total.
- It does not detect underpayments after confirmation beyond the 1% tolerance; `confirmations >= 1` with a lower amount is rejected outright.
- It does not manage refunds. Refunds are your process, on-chain, from your wallet.

---

## Reporting a vulnerability

Please follow [SECURITY.md](../SECURITY.md) rather than opening a public issue.
