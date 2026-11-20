<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renewing a checkout must invalidate the previous session's payments without
 * relying on second-precision timestamps. Every payment carries the session key
 * it was created for; renewing rotates the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crypto_checkout_payments', function (Blueprint $table) {
            $table->string('session', 36)->nullable()->after('reference')->index();
        });

        Schema::table('crypto_checkout_sessions', function (Blueprint $table) {
            $table->string('session_key', 36)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('crypto_checkout_payments', function (Blueprint $table) {
            $table->dropIndex(['session']);
            $table->dropColumn('session');
        });

        Schema::table('crypto_checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('session_key');
        });
    }
};
