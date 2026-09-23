<?php

namespace Prycegas\AubPay\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Checkout\CheckoutAttempt;
use Prycegas\AubPay\Checkout\CheckoutSession;
use Prycegas\AubPay\Enums\TransactionResult;
use Prycegas\AubPay\Events\CheckoutSessionOverpaid;
use Prycegas\AubPay\Events\CheckoutSessionPaid;
use Prycegas\AubPay\Events\PaymentSucceeded;
use Prycegas\AubPay\Exceptions\ConfigurationException;
use Prycegas\AubPay\Facades\AubPay;
use Prycegas\AubPay\Requests\CheckoutSessionRequest;
use Prycegas\AubPay\Requests\LineItem;
use Prycegas\AubPay\Responses\Transaction;
use Prycegas\AubPay\Testing\AubPayFake;
use Prycegas\AubPay\Tests\CheckoutTestCase;

class CheckoutTest extends CheckoutTestCase
{
    // ----- the session -----

    public function test_a_session_lives_at_a_hex_address_on_the_checkout_host(): void
    {
        $session = $this->openSession();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $session->id);
        $this->assertSame("https://checkout.prycegas.com/{$session->id}", $session->url());
        $this->assertSame($session->url(), AubPay::checkout()->url($session));
        // Unit prices times quantities, in minor units: 2 × ₱1,350 + ₱50.
        $this->assertSame(275000, $session->amount);
        $this->assertSame(['qrph', 'card'], $session->payment_methods);
    }

    public function test_it_refuses_a_url_the_page_would_turn_into_a_script(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/successUrl/');

        new CheckoutSessionRequest(
            lineItems: [new LineItem('Refill', 100)],
            successUrl: 'javascript:alert(document.cookie)',
            cancelUrl: 'https://prycegas.test/cart',
        );
    }

    public function test_offering_qr_ph_needs_the_wallet_rail(): void
    {
        config(['aub-pay.wallet.enabled' => false]);
        $this->app->forgetInstance(AubPayManager::class);

        // Refused in the merchant's own request, not at the customer's first click.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/aub-pay.wallet.enabled/');

        $this->openSession();
    }

    // ----- the page -----

    public function test_the_page_shows_the_order_and_both_ways_to_pay(): void
    {
        $session = $this->openSession();

        $this->get($session->url())
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSeeInOrder([
                'PRYCEGAS',
                'Reference Number',
                'PG-0a1b2c3d4e5f6',
                'Complete Your Order',
                '11 kg LPG Content',
                'Quantity: 2',
                '₱ 1,350.00',
                'Delivery Fee',
                '₱ 50.00',
                'Billed to Juan Dela Cruz, juan@example.com',
                'WVO1, Juan Dela Cruz, LPG11C 2x, false',
                'Subtotal',
                '₱ 2,750.00',
                'Fees',
                'Free',
                'Total Due',
                '₱ 2,750.00',
                'Payment Method',
                'code to pay',
                'Card',
                'Continue',
                'Privacy Policy',
            ])
            ->assertSee('value="qrph"', false)
            ->assertSee('value="card"', false);
    }

    public function test_an_address_that_is_not_a_session_is_not_found(): void
    {
        $this->get('https://checkout.prycegas.com/' . str_repeat('a', 32))->assertNotFound();
        $this->get('https://checkout.prycegas.com/not-a-session')->assertNotFound();
    }

    public function test_the_page_is_served_only_on_the_checkout_host(): void
    {
        $session = $this->openSession();

        $this->get("https://shop.prycegas.test/{$session->id}")->assertNotFound();
    }

    // ----- the card button -----

    public function test_card_sends_the_customer_to_the_cashier_page_aub_generated(): void
    {
        $session = $this->openSession();
        Http::fake(['*' => AubPayFake::success(['cashierUrl' => 'https://cashier.wepayez.com/h5/abc'], $this->signer())]);

        $this->post("{$session->url()}/pay", ['method' => 'card'])
            ->assertRedirect('https://cashier.wepayez.com/h5/abc');

        $attempt = $session->attempts()->sole();
        $this->assertSame('card', $attempt->method);
        $this->assertSame('cashier', $attempt->rail);
        $this->assertMatchesRegularExpression('/^CS_[0-9a-f]{12}_[A-Z0-9]{8}$/', $attempt->order_id);

        Http::assertSent(function (Request $request) use ($session, $attempt) {
            $order = json_decode($request->body(), true)['orderInformation'];

            $this->assertStringEndsWith('/cashier/v1/payment', $request->url());
            $this->assertSame($attempt->order_id, $order['orderId']);
            $this->assertSame(275000, $order['amount']);
            $this->assertSame($session->id, $order['attach']);
            // AUB sends the customer back to the checkout, which asks AUB what happened.
            $this->assertSame("{$session->url()}/return", $order['callbackUrl']);
            $this->assertSame('https://shop.test/aub/cashier/notify', $order['notifyUrl']);
            // Set once and never withdrawable, so it is the attempt's own short window.
            $this->assertLessThanOrEqual(15, $order['validityPeriod']);

            return true;
        });
    }

    // ----- the wallet button -----

    public function test_the_wallet_button_charges_qr_ph_and_shows_the_code_on_the_checkout(): void
    {
        $this->freezeTime();
        $session = $this->openSession();
        // AUB answers with its own expiration_date, in GMT+8 like every time on this rail.
        Http::fake(fn (Request $request) => AubPayFake::walletResponse([
            'code_url' => '00020101021228760011ph.ppmi.p2m0111AUBKPHMMXXX',
            'code_img_url' => 'https://pay.wepayez.com/pay/qrcode?uuid=abc',
            'invoiceId' => '1234567890123456',
            'expiration_date' => self::xml($request->body())['expiration_date'],
        ], AubPay::parameterSignature()));

        $this->post("{$session->url()}/pay", ['method' => 'qrph'])
            ->assertRedirect("{$session->url()}/qr");

        $attempt = $session->attempts()->sole();

        Http::assertSent(function (Request $request) use ($session, $attempt) {
            $fields = self::xml($request->body());

            $this->assertSame('pay.instapay.native.v2', $fields['service']);
            $this->assertSame($attempt->order_id, $fields['out_trade_no']);
            $this->assertSame('275000', $fields['total_fee']);
            $this->assertSame($session->id, $fields['attach']);
            $this->assertSame('https://shop.test/aub/wallet/notify', $fields['notify_url']);
            // The code's lifetime is the attempt's, in the gateway's own zone.
            $this->assertSame(now()->addMinutes(15)->setTimezone('+08:00')->format('YmdHis'), $fields['expiration_date']);

            return true;
        });

        $this->assertSame('1234567890123456', $attempt->invoice_id);
        // Stored as the same moment, not as GMT+8 wall-clock time read back in UTC — which would
        // keep a lapsed code on screen, payable-looking, for eight more hours.
        $this->assertSame(900, $attempt->refresh()->secondsRemaining());

        $this->get("{$session->url()}/qr")
            ->assertOk()
            ->assertSee('src="https://pay.wepayez.com/pay/qrcode?uuid=abc"', false)
            ->assertSee('data-countdown="900"', false)
            ->assertSeeInOrder(['Amount to pay', '₱ 2,750.00', 'Download QR code', 'Waiting for your payment']);
    }

    public function test_a_live_qr_code_is_shown_again_rather_than_issued_twice(): void
    {
        $session = $this->openSession();
        Http::fake(['*' => AubPayFake::walletResponse([
            'code_url' => '000201',
            'code_img_url' => 'https://pay.wepayez.com/pay/qrcode?uuid=abc',
            'invoiceId' => '1234567890123456',
        ], AubPay::parameterSignature())]);

        $this->post("{$session->url()}/pay", ['method' => 'qrph'])->assertRedirect("{$session->url()}/qr");
        $this->post("{$session->url()}/pay", ['method' => 'qrph'])->assertRedirect("{$session->url()}/qr");

        // Two codes would both stay payable — QR Ph cannot be closed.
        Http::assertSentCount(1);
        $this->assertSame(1, $session->attempts()->count());
    }

    public function test_a_lapsed_qr_code_is_replaced_with_a_new_order(): void
    {
        $session = $this->openSession();
        $lapsed = $this->attempt($session, 'qrph', ['expires_at' => now()->subMinute()]);
        Http::fake(['*' => AubPayFake::walletResponse([
            'code_url' => '000201',
            'code_img_url' => 'https://pay.wepayez.com/pay/qrcode?uuid=new',
            'invoiceId' => '6543210987654321',
        ], AubPay::parameterSignature())]);

        $this->post("{$session->url()}/pay", ['method' => 'qrph'])->assertRedirect("{$session->url()}/qr");

        $fresh = $session->attempts()->latest('id')->first();
        $this->assertNotSame($lapsed->order_id, $fresh->order_id);
        $this->assertSame('6543210987654321', $fresh->invoice_id);
    }

    public function test_a_refused_charge_brings_the_customer_back_with_a_message(): void
    {
        $session = $this->openSession();
        Http::fake(['*' => AubPayFake::walletResponse([
            'err_code' => 'NOAUTH',
            'err_code_des' => 'Merchant not enabled for this service',
        ], AubPay::parameterSignature(), resultCode: '1')]);

        $this->followingRedirects()
            ->post("{$session->url()}/pay", ['method' => 'qrph'])
            ->assertOk()
            ->assertSee("We couldn't start your QR Ph payment");

        // Kept, and failed: the order id was issued and must never be offered again.
        $this->assertSame(CheckoutAttempt::FAILED, $session->attempts()->sole()->status);
    }

    public function test_a_method_the_session_does_not_offer_is_refused(): void
    {
        $session = $this->openSession(['paymentMethods' => ['card']]);
        Http::fake();

        $this->post("{$session->url()}/pay", ['method' => 'qrph'])->assertRedirect($session->url());

        Http::assertNothingSent();
    }

    // ----- settling -----

    public function test_a_signed_wallet_notification_settles_the_session_once(): void
    {
        Event::fake([CheckoutSessionPaid::class]);
        $session = $this->openSession();
        $attempt = $this->attempt($session);

        $fields = [
            'status' => '0',
            'result_code' => '0',
            'pay_result' => '0',
            'out_trade_no' => $attempt->order_id,
            'transaction_id' => 'W-99',
            'total_fee' => '275000',
            'trade_type' => 'pay.instapay.native.v2',
            'invoice_id' => '1234567890123456',
        ];
        $fields['sign'] = AubPay::parameterSignature()->sign($fields);

        // AUB re-delivers until it hears `success`, so the same notification lands twice.
        foreach ([1, 2] as $delivery) {
            $this->call('POST', '/aub/wallet/notify', [], [], [], [], AubPayFake::toXml($fields))->assertOk();
        }

        Event::assertDispatchedTimes(CheckoutSessionPaid::class, 1);
        Event::assertDispatched(CheckoutSessionPaid::class, fn ($event) => $event->session->is($session) && $event->attempt->is($attempt));

        $session->refresh();
        $this->assertTrue($session->isPaid());
        $this->assertSame('qrph', $session->payment_method);
        $this->assertSame('W-99', $attempt->refresh()->gateway_reference);

        $this->get($session->url())->assertOk()->assertSee('Payment successful')->assertSee('paid via QR Ph');
    }

    public function test_a_payment_for_the_wrong_amount_does_not_settle_the_session(): void
    {
        Event::fake([CheckoutSessionPaid::class]);
        Log::spy();
        $session = $this->openSession();
        $attempt = $this->attempt($session);

        PaymentSucceeded::dispatch(new Transaction(
            orderId: $attempt->order_id,
            result: TransactionResult::Success,
            amount: 100,
        ), 'wallet');

        $this->assertFalse($session->refresh()->isPaid());
        Event::assertNotDispatched(CheckoutSessionPaid::class);
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_an_event_from_the_other_rail_is_not_taken_for_the_attempt(): void
    {
        $session = $this->openSession();
        $attempt = $this->attempt($session, 'qrph');

        PaymentSucceeded::dispatch(new Transaction(
            orderId: $attempt->order_id,
            result: TransactionResult::Success,
            amount: 275000,
        ), 'cashier');

        $this->assertFalse($session->refresh()->isPaid());
    }

    /**
     * AUB cannot withdraw a QR code or a cashier order once issued, so a customer can pay twice.
     * The second payment must surface, not vanish into an already-paid session.
     */
    public function test_a_second_payment_on_a_paid_session_is_reported_as_overpaid(): void
    {
        Event::fake([CheckoutSessionPaid::class, CheckoutSessionOverpaid::class]);
        $session = $this->openSession();
        $qr = $this->attempt($session, 'qrph');
        $card = $this->attempt($session, 'card');

        PaymentSucceeded::dispatch(new Transaction(orderId: $qr->order_id, result: TransactionResult::Success, amount: 275000), 'wallet');
        PaymentSucceeded::dispatch(new Transaction(orderId: $card->order_id, result: TransactionResult::Success, amount: 275000, referencedId: 'W2021'), 'cashier');

        Event::assertDispatchedTimes(CheckoutSessionPaid::class, 1);
        Event::assertDispatched(CheckoutSessionPaid::class, fn ($event) => $event->attempt->is($qr));
        Event::assertDispatched(CheckoutSessionOverpaid::class, fn ($event) => $event->attempt->is($card) && $event->transaction->referencedId === 'W2021');
        $this->assertSame('qrph', $session->refresh()->payment_method);
    }

    // ----- coming back from the card page -----

    public function test_a_customer_back_from_a_paid_card_page_lands_on_the_success_url(): void
    {
        $session = $this->openSession();
        $attempt = $this->attempt($session, 'card');
        $this->fakeInquiry('SUCCESS', respondedAt: '2026-09-23T10:34:56+08:00');

        $this->get("{$session->url()}/return")->assertRedirect('https://prycegas.test/orders/1/paid');

        $this->assertTrue($session->refresh()->isPaid());
        $this->assertTrue($attempt->refresh()->isPaid());
        // AUB's own time for the payment, kept as the moment it names.
        $this->assertSame('2026-09-23T02:34:56+00:00', $session->paid_at->utc()->toIso8601String());
    }

    public function test_aub_may_post_the_customer_back_and_csrf_does_not_stand_in_the_way(): void
    {
        $session = $this->openSession();
        $this->attempt($session, 'card');
        $this->fakeInquiry('SUCCESS');

        $this->post("{$session->url()}/return")->assertRedirect('https://prycegas.test/orders/1/paid');

        // The test client skips CSRF checks, so the exclusion itself is what is asserted: a POST from
        // AUB's origin carries no token, and a 419 there would strand a customer who has paid.
        $this->assertContains(
            VerifyCsrfToken::class,
            Route::getRoutes()->getByName('aub-pay.checkout.return')->excludedMiddleware(),
        );
    }

    public function test_a_declined_card_comes_back_to_the_page_with_a_reason(): void
    {
        $session = $this->openSession();
        $attempt = $this->attempt($session, 'card');
        $this->fakeInquiry('FAILED');

        $this->followingRedirects()
            ->get("{$session->url()}/return")
            ->assertOk()
            ->assertSee('Your card payment did not go through.')
            ->assertSee('Payment Method');

        $this->assertTrue($attempt->refresh()->isFailed());
        $this->assertFalse($session->refresh()->isPaid());
    }

    public function test_a_card_payment_aub_is_still_deciding_leaves_the_page_watching(): void
    {
        $session = $this->openSession();
        $this->attempt($session, 'card');
        $this->fakeInquiry('PENDING');

        $this->followingRedirects()
            ->get("{$session->url()}/return")
            ->assertOk()
            ->assertSee("We're confirming your card payment")
            ->assertSee('data-status-url=', false);
    }

    // ----- the page's own polling -----

    public function test_status_reports_a_paid_session_with_where_to_go_next(): void
    {
        $session = $this->openSession();
        $session->update(['status' => CheckoutSession::PAID, 'paid_at' => now(), 'payment_method' => 'qrph']);

        $this->getJson("{$session->url()}/status")
            ->assertOk()
            ->assertExactJson(['status' => 'paid', 'redirect_url' => 'https://prycegas.test/orders/1/paid']);
    }

    public function test_status_asks_aub_at_most_once_per_interval(): void
    {
        $session = $this->openSession();
        $attempt = $this->attempt($session, 'qrph');
        $this->travel(20)->seconds();

        Http::fake(fn (Request $request) => AubPayFake::walletResponse([
            'out_trade_no' => self::xml($request->body())['out_trade_no'],
            'trade_state' => 'NOTPAY',
            'total_fee' => '275000',
        ], AubPay::parameterSignature()));

        $this->getJson("{$session->url()}/status")->assertJson(['status' => 'active']);
        $this->getJson("{$session->url()}/status")->assertJson(['status' => 'active']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => self::xml($request->body())['service'] === 'pay.instapay.query'
            && self::xml($request->body())['invoice_id'] === $attempt->invoice_id);
    }

    public function test_status_settles_a_payment_whose_notification_never_came(): void
    {
        Event::fake([CheckoutSessionPaid::class]);
        $session = $this->openSession();
        $this->attempt($session, 'qrph');
        $this->travel(20)->seconds();

        Http::fake(fn (Request $request) => AubPayFake::walletResponse([
            'out_trade_no' => self::xml($request->body())['out_trade_no'],
            'transaction_id' => 'W-5',
            'trade_state' => 'SUCCESS',
            'total_fee' => '275000',
        ], AubPay::parameterSignature()));

        $this->getJson("{$session->url()}/status")
            ->assertJson(['status' => 'paid', 'redirect_url' => 'https://prycegas.test/orders/1/paid']);

        Event::assertDispatchedTimes(CheckoutSessionPaid::class, 1);
    }

    // ----- expiry -----

    public function test_an_expired_session_says_so_and_opens_nothing(): void
    {
        $session = $this->openSession();
        $this->travel(61)->minutes();
        Http::fake();

        $this->get($session->url())->assertOk()->assertSee('This checkout has expired');
        $this->post("{$session->url()}/pay", ['method' => 'qrph'])->assertRedirect($session->url());
        $this->getJson("{$session->url()}/status")->assertJson(['status' => 'expired']);

        Http::assertNothingSent();
    }

    public function test_expire_stops_the_page_taking_payments(): void
    {
        $session = $this->openSession();

        AubPay::checkout()->expire($session->id);

        $this->assertTrue($session->refresh()->isExpired());
        $this->get($session->url())->assertSee('This checkout has expired');
    }

    // ----- the QR image, as a file -----

    public function test_the_qr_code_downloads_as_a_file_from_the_checkout_itself(): void
    {
        $session = $this->openSession();
        $this->attempt($session, 'qrph');
        Http::fake(['*pay.wepayez.com/pay/qrcode*' => Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png'])]);

        $response = $this->get("{$session->url()}/qr/download")->assertOk();

        $this->assertSame('PNG-BYTES', $response->getContent());
        $response->assertHeader('Content-Disposition', 'attachment; filename="qrph-PG-0a1b2c3d4e5f6.png"');
    }

    public function test_only_an_image_is_passed_through(): void
    {
        $session = $this->openSession();
        $this->attempt($session, 'qrph');
        Http::fake(['*' => Http::response('<script>alert(1)</script>', 200, ['Content-Type' => 'text/html'])]);

        $this->get("{$session->url()}/qr/download")->assertStatus(502);
    }

    private function fakeInquiry(string $result, ?string $respondedAt = null): void
    {
        Http::fake(fn (Request $request) => AubPayFake::success([
            'orderInformation' => array_filter([
                'orderId' => json_decode($request->body(), true)['orderInformation']['orderId'],
                'transactionResult' => $result,
                'referencedId' => 'W2021010222244',
                'amount' => 275000,
                'responseDate' => $respondedAt,
            ]),
        ], $this->signer()));
    }
}
