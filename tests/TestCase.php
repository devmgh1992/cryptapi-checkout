<?php

namespace Nous\CryptApiCheckout\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nous\CryptApiCheckout\CryptApiCheckoutServiceProvider;
use Nous\CryptApiCheckout\Tests\Fixtures\StubOrderResolver;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Nous\\CryptApiCheckout\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [CryptApiCheckoutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('cryptapi-checkout.demo_address', 'TDemoWalletAddress000000000000000000');
        $app['config']->set('cryptapi-checkout.order_resolver', StubOrderResolver::class);
        $app['config']->set('cryptapi-checkout.window_minutes', 30);
        $app['config']->set('cryptapi-checkout.thanks_url', '/thanks/{reference}');
        $app['config']->set('cryptapi-checkout.coins', [
            [
                'ticker' => 'trc20_usdt',
                'name' => 'USDT',
                'network' => 'TRON (TRC20)',
                'group' => 'usdt',
                'address' => 'TDemoWalletAddress000000000000000000',
                'fee_pct' => 5,
                'enabled' => true,
            ],
            [
                'ticker' => 'trx',
                'name' => 'TRX',
                'network' => 'TRX',
                'group' => 'network',
                'address' => 'TDemoWalletAddress000000000000000000',
                'fee_pct' => 0,
                'enabled' => true,
            ],
            [
                // enabled but without a wallet: must never be offered
                'ticker' => 'btc',
                'name' => 'Bitcoin',
                'network' => 'BTC',
                'group' => 'network',
                'address' => '',
                'fee_pct' => 0,
                'enabled' => true,
            ],
            [
                // payable but switched off
                'ticker' => 'bep20_usdt',
                'name' => 'USDT',
                'network' => 'BNB Chain (BEP20)',
                'group' => 'usdt',
                'address' => '0x0000000000000000000000000000000000000000',
                'fee_pct' => 1,
                'enabled' => false,
            ],
        ]);

        StubOrderResolver::$paid = [];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
