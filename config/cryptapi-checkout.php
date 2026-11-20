<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | The checkout lives on your own domain: /pay/crypto/{reference}
    */
    'prefix' => env('CRYPTO_CHECKOUT_PREFIX', 'pay/crypto'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Payment window
    |--------------------------------------------------------------------------
    | Every checkout session has this many minutes. When it runs out the payment
    | is marked expired, its addresses stop being monitored and the customer is
    | offered a fresh window.
    */
    'window_minutes' => (int) env('CRYPTO_CHECKOUT_WINDOW', 30),

    /*
    |--------------------------------------------------------------------------
    | Where your orders live
    |--------------------------------------------------------------------------
    | A class implementing Nous\CryptApiCheckout\Contracts\OrderResolver.
    | It tells the package how much to charge and hears about confirmed money.
    */
    'order_resolver' => App\Support\CryptoOrderResolver::class,

    /*
    |--------------------------------------------------------------------------
    | Where the customer lands after paying
    |--------------------------------------------------------------------------
    | Use {reference} as a placeholder, or return a `thanks_url` from the
    | resolver to decide it per order.
    */
    'thanks_url' => '/',

    /*
    |--------------------------------------------------------------------------
    | Callback signing + provider label
    |--------------------------------------------------------------------------
    | The IPN URL is signed with HMAC-SHA256. Defaults to APP_KEY; set a
    | dedicated secret if you prefer.
    */
    'secret' => env('CRYPTO_CHECKOUT_SECRET'),

    'fingerprint' => env('CRYPTO_CHECKOUT_FINGERPRINT'),

    /*
    |--------------------------------------------------------------------------
    | Look & feel
    |--------------------------------------------------------------------------
    */
    'brand' => [
        'name' => env('CRYPTO_CHECKOUT_BRAND', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    | Set an address here and no call is made to CryptAPI (handy for local
    | testing); every coin will hand out this address.
    */
    'demo_address' => env('CRYPTO_CHECKOUT_DEMO_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Coins
    |--------------------------------------------------------------------------
    | ticker:   "network_token" for tokens (trc20_usdt) or the bare ticker for a
    |           native coin (trx, btc, ltc, eth)
    | address:  your payout wallet on that network
    | fee_pct:  extra % added on top of the order total when this network is used
    */
    'coins' => [

        [
            'ticker' => 'trc20_usdt',
            'name' => 'USDT',
            'network' => 'TRON (TRC20)',
            'group' => 'usdt',
            'address' => env('CRYPTO_USDT_TRC20', ''),
            'fee_pct' => (float) env('CRYPTO_FEE_TRC20', 5),
            'enabled' => true,
        ],

        [
            'ticker' => 'bep20_usdt',
            'name' => 'USDT',
            'network' => 'BNB Chain (BEP20)',
            'group' => 'usdt',
            'address' => env('CRYPTO_USDT_BEP20', ''),
            'fee_pct' => (float) env('CRYPTO_FEE_BEP20', 1),
            'enabled' => true,
        ],

        [
            'ticker' => 'sol_usdt',
            'name' => 'USDT',
            'network' => 'Solana',
            'group' => 'usdt',
            'address' => env('CRYPTO_USDT_SOL', ''),
            'fee_pct' => (float) env('CRYPTO_FEE_SOL_USDT', 1),
            'enabled' => true,
        ],

        [
            'ticker' => 'polygon_usdt',
            'name' => 'USDT',
            'network' => 'Polygon',
            'group' => 'usdt',
            'address' => env('CRYPTO_USDT_POLYGON', ''),
            'fee_pct' => (float) env('CRYPTO_FEE_POLYGON_USDT', 1),
            'enabled' => true,
        ],

        [
            'ticker' => 'trx',
            'name' => 'TRX',
            'network' => 'TRX',
            'group' => 'network',
            'address' => env('CRYPTO_TRX', ''),
            'fee_pct' => (float) env('CRYPTO_FEE_TRX', 0),
            'enabled' => true,
        ],

        [
            'ticker' => 'btc',
            'name' => 'Bitcoin',
            'network' => 'BTC',
            'group' => 'network',
            'address' => env('CRYPTO_BTC', ''),
            'fee_pct' => (float) env('CRYPTO_FEE_BTC', 0),
            'enabled' => false,
        ],

    ],
];
