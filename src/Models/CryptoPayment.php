<?php

namespace Nous\CryptApiCheckout\Models;

use Illuminate\Database\Eloquent\Model;

class CryptoPayment extends Model
{
    protected $table = 'crypto_checkout_payments';

    protected $fillable = [
        'reference', 'session', 'coin', 'mode', 'address_in', 'address_out', 'qr_code',
        'value_usd', 'value_coin', 'status', 'txid', 'confirmations',
        'payload', 'expires_at', 'paid_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'value_usd' => 'float',
        'value_coin' => 'float',
        'confirmations' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];
}
