# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.2] — 2026-11-20

### Changed
- `bacon/bacon-qr-code` constraint widened to `^2.0|^3.0`. Laravel 12 applications pin `^3.0`, so the old constraint blocked installation there. QR output is unchanged; the suite passes on v3.1.1.

## [1.0.1] — 2026-11-20

### Added
- PHPUnit suite covering the checkout flow (picker, JSON payload, expiry, renewal, IPN rejection) and the pricing rules.
- GitHub Actions workflow: syntax check on PHP 8.1/8.2/8.3, `composer validate`, test run on PHP 8.3.
- Documentation set: installation, configuration, integration, security, networks, HTTP API, troubleshooting.
- `SECURITY.md`, `CONTRIBUTING.md`.

### Changed
- README rewritten as a complete overview with requirements, flow diagram, security matrix and documentation index.
- Test suite documented as the reference for local verification; `composer test` script added.

### Fixed
- Renewal now rotates a per-reference session key (`crypto_checkout_sessions.session_key` ↔ `crypto_checkout_payments.session`) instead of comparing timestamps, so a renewed window can never be judged expired by a payment from the previous session. Requires `php artisan migrate`.
- `GET /status` persists the `expired` transition (and fires `CryptoPaymentExpired`) instead of only reporting it.

## [1.0.0] — 2026-11-20

### Added
- Self-hosted checkout page on the application's own domain, with a multi-coin picker, grouped sections and a swipeable network strip.
- In-page network switching with a per-coin browser cache (`sessionStorage`, 30 minutes) — repeated taps issue no request.
- Coin-denominated amounts via CryptAPI `/convert/`, with a per-network percentage fee applied before conversion.
- Locally rendered SVG QR codes (`bacon/bacon-qr-code`), no GD or Imagick required.
- Payment window (`window_minutes`, default 30) with countdown, automatic expiry and a one-click renewal that starts a fresh session.
- Signed, per-coin IPN callback URLs with nonce, so switching networks never collides on CryptAPI's one-invoice-per-callback rule.
- On-chain TXID verification for Tron (TRX, TRC-20), EVM chains (BNB/BEP20, Polygon, Ethereum, Base, Arbitrum, Optimism, Avalanche) and Solana (SOL, SPL tokens): destination, amount with 1% drift tolerance, confirmations, block time, self-transfer and replay checks.
- Abuse controls: 6 TXID submissions per 10 minutes per reference, `no-store` responses, HMAC tokens.
- `cryptapi:expire` scheduled command plus lazy expiry on page view and status poll.
- `OrderResolver` contract, `CryptoPaymentConfirmed` / `CryptoPaymentExpired` events, `demo_address` sandbox mode.
- Publishable config and views; self-loading migrations.

[Unreleased]: https://github.com/devmgh1992/cryptapi-checkout/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/devmgh1992/cryptapi-checkout/releases/tag/v1.0.1
