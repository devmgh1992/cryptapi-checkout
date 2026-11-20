<?php

namespace Nous\CryptApiCheckout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** The current payment session of a reference; rotating the key starts a fresh window. */
class CheckoutSession extends Model
{
    protected $table = 'crypto_checkout_sessions';

    protected $fillable = ['reference', 'started_at', 'session_key'];

    protected $casts = ['started_at' => 'datetime'];

    public static function current(string $reference): self
    {
        $session = static::firstOrCreate(
            ['reference' => $reference],
            ['started_at' => now(), 'session_key' => (string) Str::uuid()]
        );

        if (! $session->session_key) {
            $session->update(['session_key' => (string) Str::uuid(), 'started_at' => now()]);
        }

        return $session;
    }

    public function rotate(): self
    {
        $this->update(['session_key' => (string) Str::uuid(), 'started_at' => now()]);

        return $this;
    }
}
