<?php

namespace Nous\CryptApiCheckout\Events;

use Nous\CryptApiCheckout\Models\CryptoPayment;

/** The window closed without a confirmed payment. */
class CryptoPaymentExpired
{
    public function __construct(public CryptoPayment $payment)
    {
    }
}
