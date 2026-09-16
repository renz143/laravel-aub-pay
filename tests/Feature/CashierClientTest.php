<?php

namespace Prycegas\AubPay\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use InvalidArgumentException;
use Prycegas\AubPay\Enums\TransactionResult;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Facades\AubPay;
use Prycegas\AubPay\Requests\CashierOrder;
use Prycegas\AubPay\Testing\AubPayFake;
use Prycegas\AubPay\Tests\TestCase;

class CashierClientTest extends TestCase
{
    private function fakeSuccess(array $data): void
    {
        Http::fake(['*' => AubPayFake::success($data, $this->signer())]);
    }

    public function test_it_opens_a_cashier_order_and_returns_the_hosted_page(): void
    {
        $this->fakeSuccess([
            'cashierUrl' => 'https://cashier.wepayez.com/h5/abc123',
            'orderInformation' => ['orderId' => 'ORDER-1', 'amount' => 9200, 'currency' => 'PHP'],
        ]);

        $session = AubPay::cashier()->createOrder(new CashierOrder(
            orderId: 'ORDER-1',
            amount: 9200,
            description: 'Pryce Gas order',
        ));

        $this->assertSame('https://cashier.wepayez.com/h5/abc123', $session->cashierUrl);
        $this->assertSame('ORDER-1', $session->orderId);
        $this->assertSame(9200, $session->amount);
    }

    public function test_it_sends_the_documented_payload_and_headers(): void
    {
        $this->fakeSuccess(['cashierUrl' => 'https://cashier.test/x']);

        AubPay::cashier()->createOrder(new CashierOrder(
            orderId: 'ORDER-2',
            amount: 9200,
            description: 'Two cylinders',
            attach: '{"o":"42"}',
        ));

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $order = $body['orderInformation'];

            $this->assertSame('https://paymentapi-uat.wepayez.com/gateway/payment/cashier/v1/payment', $request->url());
            $this->assertSame(9200, $order['amount']);
            $this->assertSame('ORDER-2', $order['orderId']);
            // `description` is our name for it; `goodsDetail` is AUB's.
            $this->assertSame('Two cylinders', $order['goodsDetail']);
            $this->assertSame('{"o":"42"}', $order['attach']);
            // Defaults come from config rather than needing to be passed every time.
            $this->assertSame('https://shop.test/aub/cashier/notify', $order['notifyUrl']);
            $this->assertSame('https://shop.test/thank-you', $order['callbackUrl']);
            $this->assertSame(30, $order['validityPeriod']);

            $this->assertSame('800580000004', $request->header('Merchant-Id')[0]);
            $this->assertSame('en-US', $request->header('Accept-Language')[0]);
            $this->assertNotEmpty($request->header('Customer-Request-Id')[0]);

            return true;
        });
    }

    public function test_the_signature_covers_the_exact_bytes_that_are_sent(): void
    {
        $this->fakeSuccess(['cashierUrl' => 'https://cashier.test/x']);

        AubPay::cashier()->createOrder(new CashierOrder(orderId: 'ORDER-3', amount: 100));

        Http::assertSent(function (Request $request) {
            // Re-encoding the body before signing is the classic bug here; verifying the sent
            // signature against the sent bytes is what catches it.
            return $this->signer()->verify($request->body(), $request->header('Authorization')[0]);
        });
    }

    public function test_each_request_carries_a_unique_request_id(): void
    {
        $this->fakeSuccess(['cashierUrl' => 'https://cashier.test/x']);

        $ids = [];

        foreach (['A', 'B'] as $id) {
            AubPay::cashier()->createOrder(new CashierOrder(orderId: $id, amount: 100));
        }

        Http::assertSent(function (Request $request) use (&$ids) {
            $ids[] = $request->header('Customer-Request-Id')[0];

            return true;
        });

        // Reusing one is error 10, "[Customer-Request-Id] IS EXIST".
        $this->assertCount(2, array_unique($ids));
    }

    /**
     * The regression this ordering exists for: AUB does not sign its error replies, so checking
     * the signature before the response code turned every gateway error into "signature could not
     * be verified" and hid the only actionable message.
     */
    public function test_an_unsigned_error_surfaces_aubs_message_not_a_signature_complaint(): void
    {
        Http::fake(['*' => AubPayFake::error('99', 'System error')]);

        try {
            AubPay::cashier()->createOrder(new CashierOrder(orderId: 'ORDER-4', amount: 100));
            $this->fail('Expected an AubApiException.');
        } catch (AubApiException $e) {
            $this->assertSame('System error', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('signature', $e->getMessage());
            $this->assertSame('99', $e->errorCode);
        }
    }

    /**
     * The difference between "our key is wrong" and "their card was declined" is invisible in the
     * raw codes, and the two want completely different responses: one pages an engineer, the other
     * shows the customer a message.
     *
     */
    #[DataProvider('errorCodes')]
    public function test_it_classifies_errors_into_actionable_groups(
        string $code,
        bool $configuration,
        bool $declined,
        bool $indeterminate,
    ): void {
        Http::fake(['*' => AubPayFake::error($code, 'whatever')]);

        try {
            AubPay::cashier()->inquire('ORDER-5');
            $this->fail('Expected an AubApiException.');
        } catch (AubApiException $e) {
            $this->assertSame($configuration, $e->isConfigurationFault(), "{$code} configuration");
            $this->assertSame($declined, $e->code()->isDeclined(), "{$code} declined");
            $this->assertSame($indeterminate, $e->isIndeterminate(), "{$code} indeterminate");
        }
    }

    public static function errorCodes(): array
    {
        return [
            'signature error is ours to fix' => ['06', true, false, false],
            'invalid merchant is ours to fix' => ['21', true, false, false],
            'insufficient limit is a decline' => ['30', false, true, false],
            'do not honor is a decline' => ['23', false, true, false],
            // Must never be read as a failure: the customer may already have been charged.
            'timeout is indeterminate' => ['97', false, false, true],
            'unknown result is indeterminate' => ['96', false, false, true],
            '3ds pending is indeterminate' => ['01', false, false, true],
        ];
    }

    public function test_it_refuses_a_success_whose_signature_does_not_match(): void
    {
        Http::fake(['*' => AubPayFake::tampered(['cashierUrl' => 'https://evil.test/x'], $this->signer())]);

        $this->expectException(AubApiException::class);
        $this->expectExceptionMessageMatches('/signature .* could not be verified/i');

        AubPay::cashier()->createOrder(new CashierOrder(orderId: 'ORDER-7', amount: 100));
    }

    public function test_it_refuses_an_unsigned_success(): void
    {
        // No Authorization header at all: indistinguishable from a response we did not receive
        // from AUB, and must not be acted on.
        Http::fake(['*' => AubPayFake::success(['cashierUrl' => 'https://evil.test/x'], null)]);

        $this->expectException(AubApiException::class);

        AubPay::cashier()->createOrder(new CashierOrder(orderId: 'ORDER-8', amount: 100));
    }

    public function test_verification_can_be_disabled_for_uat_debugging(): void
    {
        config(['aub-pay.verify_responses' => false]);
        Http::fake(['*' => AubPayFake::success(['cashierUrl' => 'https://cashier.test/x'], null)]);

        $this->assertSame(
            'https://cashier.test/x',
            AubPay::cashier()->createOrder(new CashierOrder(orderId: 'ORDER-9', amount: 100))->cashierUrl
        );
    }

    public function test_a_success_without_a_cashier_url_is_an_error_not_an_empty_redirect(): void
    {
        $this->fakeSuccess(['orderInformation' => ['orderId' => 'ORDER-10']]);

        $this->expectException(AubApiException::class);
        $this->expectExceptionMessageMatches('/no cashierUrl/');

        AubPay::cashier()->createOrder(new CashierOrder(orderId: 'ORDER-10', amount: 100));
    }

    #[DataProvider('transactionOutcomes')]
    public function test_inquiry_reports_the_three_transaction_outcomes(string $state, bool $paid, bool $pending): void
    {
        Http::fake(['*' => AubPayFake::success([
            'orderInformation' => [
                'orderId' => 'ORDER-11',
                'transactionResult' => $state,
                'referencedId' => 'W2021010222244',
                'amount' => 9200,
                'currency' => 'PHP',
            ],
            'card' => ['paymentBrand' => 'VISA', 'cardBin' => '420000', 'last4Digits' => '0000'],
        ], $this->signer())]);

        $transaction = AubPay::cashier()->inquire('ORDER-11');

        $this->assertSame($paid, $transaction->isPaid());
        $this->assertSame($pending, $transaction->isPending());
        $this->assertSame(TransactionResult::from($state), $transaction->result);
        $this->assertSame('VISA •••• 0000', $transaction->card->label());
    }

    public static function transactionOutcomes(): array
    {
        return [
            'success' => ['SUCCESS', true, false],
            // PENDING is not a failure: AUB does not know yet.
            'pending' => ['PENDING', false, true],
            'failed' => ['FAILED', false, false],
        ];
    }

    public function test_the_success_code_S0000_is_accepted_as_well_as_00(): void
    {
        // §5.3.4 shows S0000 where §5.1.4 and §6.1 show 00. Reading a success as a failure would
        // strand a customer who has already been charged.
        Http::fake(['*' => AubPayFake::success([
            'orderInformation' => ['orderId' => 'R-1', 'transactionResult' => 'SUCCESS', 'amount' => 500],
        ], $this->signer(), 'S0000')]);

        $refund = AubPay::cashier()->refund('ORDER-12', 'W2021010222244', 500);

        $this->assertTrue($refund->isSettled());
        $this->assertSame(500, $refund->amount);
    }

    public function test_a_refund_gets_its_own_serial_number(): void
    {
        $this->fakeSuccess(['orderInformation' => ['orderId' => 'R-ORDER-13', 'transactionResult' => 'SUCCESS']]);

        AubPay::cashier()->refund('ORDER-13', 'W-REF-1', 500);

        Http::assertSent(function (Request $request) {
            $order = json_decode($request->body(), true)['orderInformation'];

            $this->assertStringEndsWith('/cashier/v1/refund', $request->url());
            // A refund is its own transaction; the original is named by originalReferencedId.
            $this->assertSame('R-ORDER-13', $order['orderId']);
            $this->assertSame('W-REF-1', $order['originalReferencedId']);
            $this->assertSame(500, $order['amount']);

            return true;
        });
    }

    public function test_it_rejects_an_over_long_order_id_rather_than_truncating_it(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/64 characters/');

        // A silently shortened orderId is an order you cannot look up afterwards.
        new CashierOrder(orderId: str_repeat('x', 65), amount: 100);
    }

    public function test_it_rejects_a_non_positive_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CashierOrder(orderId: 'ORDER-14', amount: 0);
    }
}
