<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_checkout_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->index();          // your order number
            $table->string('coin', 32)->index();               // trc20_usdt, trx, btc...
            $table->string('mode', 16)->default('api');        // api | manual
            $table->string('address_in', 191)->nullable();     // deposit address shown to the customer
            $table->string('address_out', 191)->nullable();    // your wallet
            $table->text('qr_code')->nullable();
            $table->decimal('value_usd', 14, 2)->default(0);   // invoice total incl. fee
            $table->decimal('value_coin', 24, 8)->nullable();  // exact amount in the coin
            $table->string('status', 16)->default('pending');  // pending|confirming|paid|expired
            $table->string('txid', 130)->nullable()->index();
            $table->unsignedInteger('confirmations')->default(0);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['reference', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_checkout_payments');
    }
};
