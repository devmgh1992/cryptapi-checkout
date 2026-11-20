<?php

namespace Nous\CryptApiCheckout;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Nous\CryptApiCheckout\Console\ExpirePaymentsCommand;

class CryptApiCheckoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cryptapi-checkout.php', 'cryptapi-checkout');

        $this->app->singleton(CryptApi::class);
        $this->app->singleton(TxVerifier::class);
        $this->app->singleton(Checkout::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'cryptapi-checkout');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('cryptapi:expire')->everyMinute()->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ExpirePaymentsCommand::class]);

            $this->publishes([
                __DIR__ . '/../config/cryptapi-checkout.php' => config_path('cryptapi-checkout.php'),
            ], 'cryptapi-checkout-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/cryptapi-checkout'),
            ], 'cryptapi-checkout-views');
        }
    }
}
