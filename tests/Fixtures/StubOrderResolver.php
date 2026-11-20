<?php

namespace Nous\CryptApiCheckout\Tests\Fixtures;

use Nous\CryptApiCheckout\Contracts\OrderResolver;

/** In-memory order store used by the test suite. */
class StubOrderResolver implements OrderResolver
{
    /** @var array<string, float> */
    public static array $orders = [
        'ORD-1001' => 18.99,
        'ORD-1002' => 42.00,
    ];

    /** @var array<int, array> */
    public static array $paid = [];

    public function find(string $reference): ?array
    {
        if (! array_key_exists($reference, self::$orders)) {
            return null;
        }

        return [
            'reference' => $reference,
            'amount' => self::$orders[$reference],
            'email' => 'buyer@example.com',
            'description' => 'Test order ' . $reference,
        ];
    }

    public function markPaid(string $reference, array $payment): void
    {
        self::$paid[] = $payment;
    }
}
