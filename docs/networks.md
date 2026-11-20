# Supported networks

Address creation is delegated to CryptAPI, which covers a few hundred coins. Automatic **TXID verification** is narrower: the package can only verify chains where a public, keyless API is reliable. This page lists what is verified automatically and how.

---

## Verification matrix

| Ticker pattern | Family | Explorer used | Notes |
|---|---|---|---|
| `trx` | Tron native | TronGrid `gettransactionbyid` + `gettransactioninfobyid` | Amount read from `TransferContract` |
| `trc20_usdt` (any `trc20_*`) | Tron TRC-20 | TronGrid receipt logs | `Transfer` event decoded, hex address converted to base58 |
| `bep20_*` | BNB Smart Chain | `bsc-dataseed.bnbchain.org`, `bsc-rpc.publicnode.com` | Native value and ERC-20 logs |
| `polygon_*` | Polygon PoS | `polygon-bor-rpc.publicnode.com`, `1rpc.io/matic` | Native value and ERC-20 logs |
| `eth`, `erc20_*` | Ethereum | `eth.llamarpc.com`, `ethereum-rpc.publicnode.com` | Native value and ERC-20 logs |
| `base_*` | Base | `mainnet.base.org` | |
| `arbitrum_*` | Arbitrum One | `arb1.arbitrum.io/rpc` | |
| `optimism_*` | OP Mainnet | `mainnet.optimism.io` | |
| `avax-c_*` | Avalanche C-Chain | `api.avax.network` | |
| `sol`, `sol_*` | Solana | `api.mainnet-beta.solana.com` | Token balances and lamport deltas |
| anything else | — | — | Stored as `confirming` for manual review |

Public RPC endpoints are tried in order, so a single provider outage does not break verification. If every endpoint fails the customer sees "we could not reach the network right now" and can retry — the payment is never silently rejected.

> `api.tronscanapi.com` is intentionally **not** used: its anonymous quota (3 requests/second, globally) returns `suspended for 115 s` under real traffic. TronGrid's public endpoints have no such wall.

---

## Token registry

Decimals cannot be guessed, so known tokens are mapped explicitly in `CryptApi::TOKENS`:

| Ticker | Contract | Decimals |
|---|---|---|
| `trc20_usdt` | `TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t` | 6 |
| `bep20_usdt` | `0x55d398326f99059fF775485246999027B3197955` | 18 |
| `polygon_usdt` | `0xc2132D05D31c914a87C6611C10748AEb04B58e8F` | 6 |
| `polygon_usdc` | `0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174` | 6 |
| `erc20_usdt` | `0xdAC17F958D2ee523a2206206994597C13D831ec7` | 6 |
| `erc20_usdc` | `0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48` | 6 |
| `sol_usdt` | `Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB` | 6 |
| `sol_usdc` | `EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v` | 6 |

Adding a token is a one-line change — append `'ticker' => [address, decimals, symbol]` to `TOKENS`. Without an entry, the verifier still checks existence, destination and time, but reports `needs_review` because the amount cannot be read reliably.

---

## Confirmations

The package accepts a transaction as soon as it has **one** confirmation, which is the right trade-off for digital goods where delivery can be reversed. Chains with rare reorgs (Tron, BNB, Polygon, Solana) are safe at 1; if you sell something expensive on Ethereum, raise the bar yourself by checking `confirmations` in `markPaid()`:

```php
public function markPaid(string $reference, array $payment): void
{
    if (($payment['confirmations'] ?? 0) < 12) {
        // queue a job that re-checks later instead of provisioning now
        RecheckCryptoPayment::dispatch($reference)->delay(now()->addMinutes(5));
        return;
    }
    // …
}
```

---

## Fees and amounts

```
charged_usd = round(order_total * (1 + fee_pct / 100), 2)
coin_amount = CryptAPI /convert/?value=charged_usd&from=USD&to=SYMBOL
```

The conversion is cached for 90 seconds per (network, amount) pair. Every network switch re-reads the payment row, so the amount shown is stable for the life of the address — a customer is never asked to send a moving target.

---

## Choosing which networks to enable

| Customer base | Suggested order |
|---|---|
| Global, low fees | USDT-TRC20, USDT-BEP20, USDT-Polygon, USDT-Solana |
| Tron-heavy (common in the Middle East and Asia) | TRX first, then USDT-TRC20 |
| Minimal friction | Two or three networks — long pickers reduce conversion |

Networks are grouped by the `group` key (`usdt`, `network`, `token`), so adding a coin never reshuffles the picker into an unexpected order.
