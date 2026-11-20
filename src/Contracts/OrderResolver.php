<?php

namespace Nous\CryptApiCheckout\Contracts;

/**
 * The host application owns orders, invoices and provisioning; the package only
 * needs to read the amount to charge and to be told when money arrived.
 */
interface OrderResolver
{
    /**
     * @return array{reference: string, amount: float, email?: ?string, currency?: ?string, description?: ?string, thanks_url?: ?string}|null
     *         null when the reference is unknown or already paid.
     */
    public function find(string $reference): ?array;

    /**
     * Money confirmed (IPN, manual TXID verification or admin action).
     *
     * @param  array{reference: string, coin: string, txid: ?string, usd: float, coin_amount: ?float, confirmations: int, verified: array}  $payment
     */
    public function markPaid(string $reference, array $payment): void;
}
