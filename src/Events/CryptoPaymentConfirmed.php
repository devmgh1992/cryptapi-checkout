<?php

namespace Nous\CryptApiCheckout\Events;

use Nous\CryptApiCheckout\Models\CryptoPayment;

/** Money reached the wallet: the host should provision whatever was bought. */
class CryptoPaymentConfirmed
{
    public function __construct(public CryptoPayment $payment, public array $data = [])
    {
    }
}
