<?php

namespace Prycegas\AubPay\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prycegas\AubPay\Events\NotificationReceived;
use Prycegas\AubPay\Events\PaymentFailed;
use Prycegas\AubPay\Events\PaymentPending;
use Prycegas\AubPay\Events\PaymentSucceeded;
use Prycegas\AubPay\Facades\AubPay;
use Prycegas\AubPay\Testing\AubPayFake;
use Prycegas\AubPay\Tests\TestCase;

class WebhookTest extends TestCase
{
    private function notification(string $orderId = 'ORDER-1', string $result = 'SUCCESS'): array
    {
        return [
            'code' => '00',
            'message' => 'Success',
            'data' => [
                'orderInformation' => [
                    'referencedId' => 'W2021010222244',
                    'orderId' => $orderId,
                    'transactionResult' => $result,
                    'amount' => 9200,
                    'currency' => 'PHP',
                ],
            ],
        ];
    }

    private function fakeInquiry(string $result = 'SUCCESS'): void
    {
        Http::fake(['*' => AubPayFake::success([
            'orderInformation' => [
                'orderId' => 'ORDER-1',
                'transactionResult' => $result,
                'referencedId' => 'W2021010222244',
                'amount' => 9200,
            ],
        ], $this->signer())]);
    }

    public function test_the_routes_are_registered_under_the_configured_prefix(): void
    {
        $this->fakeInquiry();

        $this->postJson('/aub/cashier/notify', $this->notification())->assertOk();
        $this->post('/aub/wallet/notify')->assertOk();
    }

    /**
     * §5.4.3: the body must be the literal string `SUCCESS`. A JSON body reads to AUB as a failed
     * delivery and it re-posts for hours.
     */
    public function test_it_answers_with_the_literal_string_success(): void
    {
        $this->fakeInquiry();

        $response = $this->postJson('/aub/cashier/notify', $this->notification());

        $response->assertOk();
        $this->assertSame('SUCCESS', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_a_paid_notification_is_confirmed_by_inquiry_and_raises_the_event(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class, PaymentPending::class]);
        $this->fakeInquiry('SUCCESS');

        $this->postJson('/aub/cashier/notify', $this->notification())->assertOk();

        // The outcome must come from the inquiry, and the inquiry must actually have happened.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cashier/v1/inquiry'));
        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->orderId() === 'ORDER-1' && $e->rail === 'cashier');
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /**
     * The security property of the whole design: the notification body is never believed. A forged
     * "you were paid" buys an attacker one inquiry call and nothing more.
     */
    public function test_a_forged_success_notification_does_not_produce_a_payment(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        // The gateway's answer is the truth, and it says this was never paid.
        $this->fakeInquiry('FAILED');

        $this->postJson('/aub/cashier/notify', $this->notification('ORDER-1', 'SUCCESS'))->assertOk();

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_a_pending_outcome_is_not_reported_as_a_failure(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class, PaymentPending::class]);
        $this->fakeInquiry('PENDING');

        $this->postJson('/aub/cashier/notify', $this->notification())->assertOk();

        Event::assertDispatched(PaymentPending::class);
        Event::assertNotDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /**
     * A transient failure must be retried, or a real payment is lost. AUB only retries what it
     * does not get a clean acknowledgement for.
     */
    public function test_an_unreachable_gateway_asks_aub_to_retry(): void
    {
        Event::fake([PaymentSucceeded::class]);
        Http::fake(['*' => AubPayFake::error('97', 'TRANSACTION TIMEOUT')]);

        $response = $this->postJson('/aub/cashier/notify', $this->notification());

        $response->assertStatus(500);
        $this->assertSame('FAIL', $response->getContent());
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /**
     * An outcome no retry can change is acknowledged, so the retry cycle stops.
     */
    public function test_an_unknown_order_is_acknowledged_rather_than_retried_forever(): void
    {
        Event::fake([PaymentSucceeded::class]);
        Http::fake(['*' => AubPayFake::error('05', 'ORDER INFORMATION DOES NOT EXIST')]);

        $response = $this->postJson('/aub/cashier/notify', $this->notification('WHO-KNOWS'));

        $response->assertOk();
        $this->assertSame('SUCCESS', $response->getContent());
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_a_notification_without_an_order_id_is_acknowledged_and_logged(): void
    {
        Event::fake([NotificationReceived::class, PaymentSucceeded::class]);

        $response = $this->postJson('/aub/cashier/notify', ['code' => '00', 'data' => []]);

        $response->assertOk();
        Event::assertDispatched(NotificationReceived::class, fn ($e) => $e->orderId === null);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_it_finds_the_order_id_wherever_the_gateway_puts_it(): void
    {
        foreach ([
            ['data' => ['orderInformation' => ['orderId' => 'ORDER-1']]],
            ['orderInformation' => ['orderId' => 'ORDER-1']],
            ['orderId' => 'ORDER-1'],
        ] as $shape) {
            Event::fake([NotificationReceived::class]);
            $this->fakeInquiry();

            $this->postJson('/aub/cashier/notify', $shape)->assertOk();

            Event::assertDispatched(NotificationReceived::class, fn ($e) => $e->orderId === 'ORDER-1');
        }
    }

    // ----- wallet rail -----

    /**
     * Unlike the cashier notification, this one is signed — so a verified signature is evidence,
     * and the outcome can be read straight off it. That matters: §6.1 gives a five-second budget,
     * and a gateway round-trip inside it is a risk.
     */
    public function test_a_signed_wallet_notification_is_trusted_without_a_second_call(): void
    {
        Event::fake([PaymentSucceeded::class]);
        Http::fake();

        $fields = [
            'status' => '0',
            'result_code' => '0',
            'pay_result' => '0',
            'out_trade_no' => 'ORDER_100001',
            'transaction_id' => 'W-99',
            'total_fee' => '9200',
            'trade_type' => 'pay.gcash.webpay',
        ];
        $fields['sign'] = AubPay::parameterSignature()->sign($fields);

        $response = $this->call('POST', '/aub/wallet/notify', [], [], [], [], AubPayFake::toXml($fields));

        $response->assertOk();
        // §11.4: the acknowledgement is the pure string `success`.
        $this->assertSame('success', $response->getContent());
        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->rail === 'wallet' && $e->orderId() === 'ORDER_100001');
        Http::assertNothingSent();
    }

    public function test_an_unsigned_wallet_notification_falls_back_to_asking_the_gateway(): void
    {
        Event::fake([PaymentSucceeded::class]);
        Http::fake(['*' => AubPayFake::walletResponse([
            'out_trade_no' => 'ORDER_100001',
            'transaction_id' => 'W-99',
            'trade_state' => 'SUCCESS',
            'total_fee' => '9200',
        ], AubPay::parameterSignature())]);

        // No `sign` at all — cannot be trusted, so it must be confirmed rather than acted on.
        $response = $this->call('POST', '/aub/wallet/notify', [], [], [], [], AubPayFake::toXml([
            'out_trade_no' => 'ORDER_100001',
            'result_code' => '0',
        ]));

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gateway.wepayez.com'));
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_a_wallet_notification_without_an_order_reference_is_acknowledged(): void
    {
        $response = $this->call('POST', '/aub/wallet/notify', [], [], [], [], '<xml><status>0</status></xml>');

        $response->assertOk();
        $this->assertSame('success', $response->getContent());
    }
}
