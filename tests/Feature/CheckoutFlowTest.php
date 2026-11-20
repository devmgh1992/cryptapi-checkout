<?php

namespace Nous\CryptApiCheckout\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nous\CryptApiCheckout\Checkout;
use Nous\CryptApiCheckout\Models\CryptoPayment;
use Nous\CryptApiCheckout\Tests\Fixtures\StubOrderResolver;
use Nous\CryptApiCheckout\Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Http::fake([
            // CryptAPI conversion: 1 USD = 3.25 TRX, 1 USD = 1 USDT
            'api.cryptapi.io/trx/convert/*' => Http::response([
                'status' => 'success',
                'value_coin' => '61.72',
                'exchange_rate' => 3.25,
            ]),
            'api.cryptapi.io/trc20/usdt/convert/*' => Http::response([
                'status' => 'success',
                'value_coin' => '19.94',
                'exchange_rate' => 1.0,
            ]),
            'api.cryptapi.io/info*' => Http::response([
                'trx' => ['coin' => 'TRX', 'ticker' => 'trx', 'logo' => 'https://cdn.test/trx.png'],
                'trc20' => [
                    'usdt' => ['coin' => 'Tether', 'ticker' => 'usdt', 'name' => 'TRON', 'logo' => 'https://cdn.test/usdt.png'],
                ],
            ]),
            // Tron explorer: unknown transaction
            'api.trongrid.io/*' => Http::response([]),
        ]);
    }

    public function test_picker_lists_only_payable_coins(): void
    {
        $response = $this->get('/pay/crypto/ORD-1001');

        $response->assertOk();
        $response->assertSee('Choose your network', false);
        $response->assertSee('USDT', false);
        $response->assertSee('TRX', false);
        // enabled but no wallet address configured
        $response->assertDontSee('Bitcoin', false);
        // configured but disabled
        $response->assertDontSee('BNB Chain', false);
    }

    public function test_unknown_reference_returns_404(): void
    {
        $this->get('/pay/crypto/NOPE-1')->assertNotFound();
    }

    public function test_coin_endpoint_returns_address_and_coin_denominated_amount(): void
    {
        $response = $this->getJson('/pay/crypto/ORD-1001/coin/trc20_usdt');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'api')
            ->assertJsonPath('address', 'TDemoWalletAddress000000000000000000')
            ->assertJsonPath('ticker_label', 'USDT')
            ->assertJsonPath('base', 18.99)
            ->assertJsonPath('fee', 0.95)
            ->assertJsonPath('total', 19.94);

        // the amount the customer must send is denominated in the coin
        $this->assertSame('19.94', $response->json('value_coin'));
        $this->assertNotNull($response->json('expires_at'));
        $this->assertStringContainsString('<svg', (string) $response->json('qr_svg'));
    }

    public function test_switching_network_reuses_the_same_window(): void
    {
        $first = $this->getJson('/pay/crypto/ORD-1001/coin/trc20_usdt')->json();
        $second = $this->getJson('/pay/crypto/ORD-1001/coin/trx')->json();

        $this->assertSame($first['expires_at'], $second['expires_at'], 'the window must not reset on a network switch');
        $this->assertNotNull($first['address']);
        $this->assertNotNull($second['address']);
        $this->assertSame(2, CryptoPayment::where('reference', 'ORD-1001')->count());
    }

    public function test_status_reports_pending_then_expiry(): void
    {
        $this->getJson('/pay/crypto/ORD-1001/coin/trx');

        $this->getJson('/pay/crypto/ORD-1001/status')
            ->assertOk()
            ->assertJsonPath('paid', false)
            ->assertJsonPath('expired', false);

        CryptoPayment::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/pay/crypto/ORD-1001/status')
            ->assertOk()
            ->assertJsonPath('expired', true)
            ->assertJsonPath('status', 'expired');
    }

    public function test_expired_session_is_cancelled_and_can_be_renewed(): void
    {
        $this->getJson('/pay/crypto/ORD-1001/coin/trx');

        CryptoPayment::query()->update(['expires_at' => now()->subMinute(), 'status' => 'expired']);

        $page = $this->get('/pay/crypto/ORD-1001');
        $this->assertSame(200, $page->status(), 'expired page status: ' . $page->status());
        $page->assertSee('This payment window closed', false);

        $this->assertSame(410, $this->getJson('/pay/crypto/ORD-1001/coin/trx')->status());

        $this->post('/pay/crypto/ORD-1001/renew')->assertRedirect('/pay/crypto/ORD-1001');

        // a fresh window is available again after the renewal
        $this->getJson('/pay/crypto/ORD-1001/coin/trx')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_callback_rejects_a_forged_token(): void
    {
        $this->getJson('/pay/crypto/ORD-1001/coin/trx');

        $this->get('/pay/crypto/callback/ORD-1001/deadbeef')
            ->assertForbidden();
    }

    public function test_callback_confirms_a_payment_and_notifies_the_resolver(): void
    {
        $payment = $this->getJson('/pay/crypto/ORD-1001/coin/trx')->json();

        $token = app(Checkout::class)->sign('ORD-1001', 'trx');

        $query = http_build_query([
            'address_in' => $payment['address'],
            'txid_in' => str_repeat('a', 64),
            'confirmations' => 3,
        ]);

        $this->get('/pay/crypto/callback/ORD-1001/' . $token . '?' . $query)
            ->assertOk()
            ->assertSee('ok');

        $row = CryptoPayment::where('reference', 'ORD-1001')->first();
        $this->assertSame('paid', $row->status);
        $this->assertNotNull($row->paid_at);
        $this->assertCount(1, StubOrderResolver::$paid);
        $this->assertSame(str_repeat('a', 64), StubOrderResolver::$paid[0]['txid']);
    }

    public function test_callback_metadata_is_ignored_when_deciding_the_amount(): void
    {
        $payment = $this->getJson('/pay/crypto/ORD-1001/coin/trx')->json();
        $token = app(Checkout::class)->sign('ORD-1001', 'trx');

        // a compromised client claims a different amount: it must not be trusted
        $query = http_build_query([
            'address_in' => $payment['address'],
            'confirmations' => 1,
            'value_coin' => '0.0001',
            'coin_amount' => '0.0001',
        ]);

        $this->get('/pay/crypto/callback/ORD-1001/' . $token . '?' . $query)->assertOk();

        $this->assertSame('paid', CryptoPayment::where('reference', 'ORD-1001')->first()->status);
    }

    public function test_manual_txid_is_verified_on_chain_and_rejected_when_unknown(): void
    {
        $this->getJson('/pay/crypto/ORD-1001/coin/trx');

        $response = $this->from('/pay/crypto/ORD-1001')
            ->post('/pay/crypto/ORD-1001/txid', ['txid' => str_repeat('a', 64)]);

        $response->assertRedirect('/pay/crypto/ORD-1001');
        $response->assertSessionHas('cryptapi_error');

        $this->assertSame('pending', CryptoPayment::where('reference', 'ORD-1001')->first()->status);
        $this->assertEmpty(StubOrderResolver::$paid);
    }

    public function test_txid_submission_is_throttled(): void
    {
        $this->getJson('/pay/crypto/ORD-1001/coin/trx');

        for ($i = 0; $i < 6; $i++) {
            $this->post('/pay/crypto/ORD-1001/txid', ['txid' => str_repeat('a', 64)]);
        }

        $response = $this->post('/pay/crypto/ORD-1001/txid', ['txid' => str_repeat('b', 64)]);
        $response->assertSessionHas('cryptapi_error', 'Too many attempts. Please try again in 10 minutes.');
    }

    public function test_paid_order_redirects_to_the_thanks_page(): void
    {
        $payment = $this->getJson('/pay/crypto/ORD-1001/coin/trx')->json();
        $token = app(Checkout::class)->sign('ORD-1001', 'trx');

        $query = http_build_query([
            'address_in' => $payment['address'],
            'confirmations' => 1,
        ]);

        $this->get('/pay/crypto/callback/ORD-1001/' . $token . '?' . $query)->assertOk();

        $this->get('/pay/crypto/ORD-1001')->assertRedirect('/thanks/ORD-1001');
    }

    public function test_expire_command_closes_abandoned_checkouts(): void
    {
        $this->getJson('/pay/crypto/ORD-1001/coin/trx');
        $this->getJson('/pay/crypto/ORD-1002/coin/trx');

        CryptoPayment::where('reference', 'ORD-1002')->update(['expires_at' => now()->subHour()]);

        $this->artisan('cryptapi:expire')
            ->expectsOutput('expired crypto payments: 1')
            ->assertSuccessful();

        $this->assertSame('expired', CryptoPayment::where('reference', 'ORD-1002')->first()->status);
        $this->assertSame('pending', CryptoPayment::where('reference', 'ORD-1001')->first()->status);
    }
}
