# Contributing

Thanks for taking the time to contribute.

---

## Before you start

- For a **bug**, open an issue with the Laravel version, PHP version, the coin involved and the relevant `storage/logs/laravel.log` lines (redact addresses and order references).
- For a **security problem**, do not open an issue — follow [SECURITY.md](SECURITY.md).
- For a **feature**, describe the use case first. This package deliberately stays small: it is a checkout, not a billing system.

## Development setup

```bash
git clone https://github.com/devmgh1992/cryptapi-checkout
cd cryptapi-checkout
composer install
vendor/bin/phpunit
```

The suite runs against an in-memory SQLite database with a stub order resolver, and uses `demo_address` so no request is made to CryptAPI.

To exercise the page inside a real application, add the repository to any Laravel 10/11/12 project as a `path` repository:

```json
{ "type": "path", "url": "../cryptapi-checkout", "options": { "symlink": true } }
```

## Pull requests

1. One topic per pull request.
2. Follow the existing style: typed parameters, early returns, no facades inside models, no comments that restate the code.
3. Behaviour changes need a test. New verifiers or coins need a row in `docs/networks.md`.
4. Update `CHANGELOG.md` under `[Unreleased]`.
5. Run before pushing:

```bash
vendor/bin/phpunit
composer validate --strict
find src config database routes -name '*.php' -exec php -l {} \;
```

## Coding conventions

| Area | Rule |
|---|---|
| Namespace | `Nous\CryptApiCheckout` |
| PHP | 8.1+ — constructor promotion, readonly where it helps, no `declare(strict_types=1)` inconsistency (the codebase omits it) |
| Money | always USD floats rounded to 2 decimals; coin amounts to 8 decimals |
| Logging | `Log::warning('cryptapi-checkout: …')` for anything an operator must see |
| Tests | `tests/Feature` for HTTP behaviour, `tests/Unit` for pricing and parsing |

## Commit messages

Conventional commits, English only:

```
feat(tron): verify TRC-20 transfers with 19 confirmations
fix(window): keep the timer across network switches
docs(readme): add renewal flow diagram
```

## Adding a network verifier

1. Add the ticker family to `CryptApi::EVM_RPC` (EVM) or write a `verify*` method in `TxVerifier`.
2. Register known tokens in `CryptApi::TOKENS` with their decimals.
3. Add a unit test that parses a recorded provider response (store the fixture under `tests/fixtures`).
4. Document it in `docs/networks.md`.

## License

By contributing you agree that your work is released under the [MIT License](LICENSE).
