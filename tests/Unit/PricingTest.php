<?php

namespace Nous\CryptApiCheckout\Tests\Unit;

use Nous\CryptApiCheckout\CryptApi;
use Nous\CryptApiCheckout\Tests\TestCase;

class PricingTest extends TestCase
{
    private function coin(string $ticker): array
    {
        $coin = app(CryptApi::class)->findCoin($ticker);

        $this->assertNotNull($coin, 'coin not registered: ' . $ticker);

        return $coin;
    }

    public function test_only_payable_coins_are_offered(): void
    {
        $tickers = array_column(app(CryptApi::class)->payableCoins(), 'ticker');

        $this->assertContains('trc20_usdt', $tickers);
        $this->assertContains('trx', $tickers);
        $this->assertNotContains('btc', $tickers, 'a coin without a wallet must not be payable');
        $this->assertNotContains('bep20_usdt', $tickers, 'a disabled coin must not be payable');
    }

    public function test_fee_is_applied_per_network_only(): void
    {
        $api = app(CryptApi::class);

        // 5% on USDT-TRC20 …
        $this->assertSame(19.94, $api->amountFor(18.99, $this->coin('trc20_usdt')));
        $this->assertSame(0.95, $api->feeFor(18.99, $this->coin('trc20_usdt')));

        // … and nothing on TRX
        $this->assertSame(18.99, $api->amountFor(18.99, $this->coin('trx')));
        $this->assertSame(0.0, $api->feeFor(18.99, $this->coin('trx')));
    }

    public function test_fee_rounds_to_cents(): void
    {
        $api = app(CryptApi::class);

        $this->assertSame(19.94, $api->amountFor(18.99, $this->coin('trc20_usdt')));
        $this->assertSame(101.0, $api->amountFor(96.19, $this->coin('trc20_usdt')));
    }

    public function test_ticker_normalisation_builds_cryptapi_paths(): void
    {
        $api = app(CryptApi::class);

        $this->assertSame('trc20/usdt', $api->pathFor('trc20_usdt'));
        $this->assertSame('trx', $api->pathFor('TRX'));
        $this->assertSame('trc20_usdt', $api->normalizeTicker('trc20'));
        $this->assertSame('bep20/usdt', $api->pathFor('bep20/USDT'));
        $this->assertSame('USDT', $api->symbolFor('trc20_usdt'));
        $this->assertSame('POL', $api->symbolFor('polygon_pol'));
    }

    public function test_labels_are_human_readable(): void
    {
        $api = app(CryptApi::class);

        $this->assertSame('USDT · TRON (TRC20)', $api->labelFor($this->coin('trc20_usdt')));
        $this->assertSame('TRX', $api->labelFor($this->coin('trx')));
    }

    public function test_groups_are_inferred_from_the_ticker(): void
    {
        $api = app(CryptApi::class);

        $this->assertSame('usdt', $api->groupFor('trc20_usdt'));
        $this->assertSame('network', $api->groupFor('trx'));
        $this->assertSame('token', $api->groupFor('bep20_bnb'));
    }

    public function test_token_registry_covers_the_supported_stablecoins(): void
    {
        foreach (['trc20_usdt', 'bep20_usdt', 'polygon_usdt', 'erc20_usdt', 'sol_usdt'] as $ticker) {
            $this->assertArrayHasKey($ticker, CryptApi::TOKENS, 'missing token contract for ' . $ticker);
            $this->assertCount(3, CryptApi::TOKENS[$ticker]);
            $this->assertGreaterThan(0, CryptApi::TOKENS[$ticker][1], 'decimals must be positive');
        }
    }

    public function test_window_is_clamped_to_a_sane_range(): void
    {
        $api = app(CryptApi::class);

        config(['cryptapi-checkout.window_minutes' => 30]);
        $this->assertSame(30, $api->windowMinutes());

        config(['cryptapi-checkout.window_minutes' => 1]);
        $this->assertSame(30, $api->windowMinutes());

        config(['cryptapi-checkout.window_minutes' => 90]);
        $this->assertSame(90, $api->windowMinutes());

        config(['cryptapi-checkout.window_minutes' => 999]);
        $this->assertSame(30, $api->windowMinutes());
    }
}
