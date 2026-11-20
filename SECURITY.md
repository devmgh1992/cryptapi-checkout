# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | ✅ |
| < 1.0 | ❌ |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately through GitHub's [security advisory form](https://github.com/devmgh1992/cryptapi-checkout/security/advisories/new), or by email if you already have the maintainer's address.

Include:

- affected version (`composer show devmgh1992/cryptapi-checkout`)
- the coin/network involved, if relevant
- a reproduction: request, payload, logs (`cryptapi-checkout:` lines)
- impact assessment — especially anything that could credit an order without a matching on-chain payment

**Redact wallet addresses, order references and API secrets.** If a real payment is involved, never move funds while investigating.

## What to expect

| Stage | Target |
|---|---|
| Acknowledgement | 72 hours |
| Initial assessment | 7 days |
| Fix for high/critical | 14 days, coordinated disclosure |

Credit is given in the release notes unless you ask otherwise.

## In scope

- Forging an IPN (`/pay/crypto/callback/…`) without the HMAC token, or using a token minted for another reference or coin
- Marking a payment `paid` with a fabricated, replayed or self-sent TXID
- Reading or mutating another customer's payment rows through the public endpoints
- Bypassing the payment window (confirming after expiry)
- Injection through the `reference`, `ticker` or `txid` parameters
- Any path that leads to activating an order without confirmed funds

## Out of scope

- Issues that require the attacker to control your payout wallet private keys
- `demo_address` left enabled in production (documented misconfiguration)
- CryptAPI's own availability, pricing or rate limits
- Denial of service through sheer request volume — use your web server / CDN rate limiting
- Missing security headers that are set by your application, not the package

## Hardening reminders

The package assumes a correctly configured application: HTTPS in production, `APP_KEY` kept secret, scheduler running, wallet addresses verified, and a resolver that authorises the reference (see [docs/security.md](docs/security.md)).
