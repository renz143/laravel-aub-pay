<?php

namespace Prycegas\AubPay\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Prycegas\AubPay\Crypto\ParameterSignature;
use Prycegas\AubPay\Enums\WalletService;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Facades\AubPay;
use Prycegas\AubPay\Requests\WalletCharge;
use Prycegas\AubPay\Testing\AubPayFake;
use Prycegas\AubPay\Tests\TestCase;

class WalletClientTest extends TestCase
{
    private function signature(): ParameterSignature
    {
        return AubPay::parameterSignature();
    }

    private function charge(string $outTradeNo = 'ORDER_100001'): WalletCharge
    {
        return new WalletCharge(
            service: WalletService::GcashWeb,
            outTradeNo: $outTradeNo,
            totalFee: 9200,
            body: 'Pryce Gas order',
        );
    }

    public function test_it_starts_a_webpay_charge_and_returns_a_redirect_target(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse([
            'out_trade_no' => 'ORDER_100001',
            'transaction_id' => '102520000212201911081086849655',
            'pay_url' => 'https://gcash.test/pay/abc',
            'total_fee' => '9200',
        ], $this->signature())]);

        $result = AubPay::wallet()->charge($this->charge());

        $this->assertSame('https://gcash.test/pay/abc', $result->payUrl);
        $this->assertSame('https://gcash.test/pay/abc', $result->target());
        $this->assertSame(9200, $result->totalFee);
    }

    public function test_a_qr_charge_returns_the_code_payload_as_its_target(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse([
            'out_trade_no' => 'ORDER_100002',
            'code_url' => '00020101021230550018QRPH_MERCHANT',
            'code_img_url' => 'https://gateway.test/qrcode/code?uuid=abc',
        ], $this->signature())]);

        $result = AubPay::wallet()->charge(new WalletCharge(
            service: WalletService::GcashQr,
            outTradeNo: 'ORDER_100002',
            totalFee: 100,
            body: 'Test',
        ));

        $this->assertSame('00020101021230550018QRPH_MERCHANT', $result->target());
        $this->assertSame('https://gateway.test/qrcode/code?uuid=abc', $result->codeImageUrl);
    }

    /**
     * §5.4.2: QR Ph names the order by a fresh invoiceId and does not echo out_trade_no — so the
     * result must fill that in from the request, or a caller storing `$result->outTradeNo` stores ''.
     */
    public function test_a_qr_ph_charge_returns_its_invoice_id_and_expiry(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse([
            'code_url' => '00020101021228760011ph.ppmi.p2m0111AUBKPHMMXXX',
            'code_img_url' => 'https://pay.wepayez.com/pay/qrcode?uuid=abc',
            'uuid' => 'abc',
            'expiration_date' => '20260923153000',
            'invoiceId' => '1234567890123456',
        ], $this->signature())]);

        $result = AubPay::wallet()->charge(new WalletCharge(
            service: WalletService::InstapayQrV2,
            outTradeNo: 'ORDER_100003',
            totalFee: 275000,
            body: 'Pryce Gas order',
        ));

        $this->assertSame('ORDER_100003', $result->outTradeNo);
        $this->assertSame('1234567890123456', $result->invoiceId);
        $this->assertSame('abc', $result->uuid);
        $this->assertSame('https://pay.wepayez.com/pay/qrcode?uuid=abc', $result->codeImageUrl);
        // GMT+8 on the wire, so 15:30 there is 07:30 UTC.
        $this->assertSame('2026-09-23T07:30:00+00:00', $result->expiresAt->utc()->toIso8601String());
    }

    public function test_gateway_times_are_sent_as_gmt_plus_8_whatever_zone_they_arrive_in(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse(['code_url' => 'x', 'code_img_url' => 'https://x.test/qr'], $this->signature())]);

        AubPay::wallet()->charge(new WalletCharge(
            service: WalletService::InstapayQrV2,
            outTradeNo: 'ORDER_100004',
            totalFee: 100,
            body: 'Test',
            // An app on UTC formatting this itself would send 20260923073000 — 7:30 in Manila,
            // eight hours before the moment it meant.
            expirationDate: CarbonImmutable::parse('2026-09-23 07:30:00', 'UTC'),
        ));

        Http::assertSent(function (Request $request) {
            $fields = (array) simplexml_load_string($request->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

            $this->assertSame('pay.instapay.native.v2', (string) $fields['service']);
            $this->assertSame('20260923153000', (string) $fields['expiration_date']);
            $this->assertArrayNotHasKey('time_expire', $fields);

            return true;
        });
    }

    public function test_a_gateway_time_given_as_a_string_must_already_be_in_its_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/yyyyMMddHHmmss in GMT\+8/');

        new WalletCharge(WalletService::InstapayQrV2, 'ORDER_100005', 100, 'body', expirationDate: '2026-09-23 15:30');
    }

    public function test_a_query_with_an_invoice_id_goes_to_the_instapay_service(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse([
            'out_trade_no' => 'ORDER_100003',
            'transaction_id' => 'W-7',
            'trade_state' => 'SUCCESS',
            'total_fee' => '275000',
            'invoice_id' => '1234567890123456',
        ], $this->signature())]);

        $transaction = AubPay::wallet()->query(outTradeNo: 'ORDER_100003', invoiceId: '1234567890123456');

        $this->assertTrue($transaction->isPaid());
        Http::assertSent(function (Request $request) {
            $fields = (array) simplexml_load_string($request->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

            return (string) $fields['service'] === 'pay.instapay.query'
                && (string) $fields['invoice_id'] === '1234567890123456'
                && (string) $fields['out_trade_no'] === 'ORDER_100003';
        });
    }

    /**
     * §5.4.4 lists no trade_state for a QR Ph query. Its absence must read as "not yet", never as
     * a failure, and the order must still be the one that was asked about.
     */
    public function test_a_qr_ph_query_without_a_trade_state_is_pending_not_failed(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse([
            'trade_type' => 'pay.instapay.native.v2',
            'invoice_id' => '1234567890123456',
        ], $this->signature())]);

        $transaction = AubPay::wallet()->query(outTradeNo: 'ORDER_100003', invoiceId: '1234567890123456');

        $this->assertTrue($transaction->isPending());
        $this->assertSame('ORDER_100003', $transaction->orderId);
    }

    public function test_it_sends_signed_flat_xml_with_the_envelope_fields_filled_in(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse(['out_trade_no' => 'ORDER_100001', 'pay_url' => 'https://x.test'], $this->signature())]);

        AubPay::wallet()->charge($this->charge());

        Http::assertSent(function (Request $request) {
            $fields = (array) simplexml_load_string($request->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
            $fields = array_map(fn ($v) => is_scalar($v) ? (string) $v : $v, $fields);

            $this->assertSame('https://gateway.wepayez.com/pay/gateway', $request->url());
            $this->assertSame('pay.gcash.webpay', $fields['service']);
            $this->assertSame('127500000158', $fields['mch_id']);
            $this->assertSame('9200', $fields['total_fee']);
            // Envelope fields the transport supplies so callers cannot forget them.
            $this->assertSame('2.0', $fields['version']);
            $this->assertSame('UTF-8', $fields['charset']);
            $this->assertSame('SHA256', $fields['sign_type']);
            $this->assertNotEmpty($fields['nonce_str']);
            // mch_create_ip is mandatory; absent is rejected, so it is defaulted.
            $this->assertNotEmpty($fields['mch_create_ip']);

            $this->assertTrue($this->signature()->verify($fields), 'the request must carry a valid sign');

            return true;
        });
    }

    public function test_a_protocol_level_rejection_is_reported_with_its_message(): void
    {
        // status is the communication flag; 500/SYSERR is the documented protocol error shape.
        Http::fake(['*' => Http::response('<xml><status>500</status><message><![CDATA[SYSERR]]></message></xml>')]);

        $this->expectException(AubApiException::class);
        $this->expectExceptionMessage('SYSERR');

        AubPay::wallet()->charge($this->charge());
    }

    /**
     * The bug this guards: `status` says the call was understood, `result_code` says whether the
     * transaction worked. Checking only the first reads a declined payment as an approval.
     */
    public function test_a_successful_call_about_a_failed_transaction_is_still_a_failure(): void
    {
        Http::fake(['*' => AubPayFake::walletResponse([
            'out_trade_no' => 'ORDER_100001',
            'err_code' => 'AUTHCODE_EXPIRE',
            'err_code_des' => 'The authorisation code has expired',
        ], $this->signature(), status: '0', resultCode: '1')]);

        try {
            AubPay::wallet()->charge($this->charge());
            $this->fail('Expected an AubApiException.');
        } catch (AubApiException $e) {
            $this->assertSame('The authorisation code has expired', $e->getMessage());
            $this->assertSame('AUTHCODE_EXPIRE', $e->errorCode);
        }
    }

    public function test_it_refuses_a_response_whose_signature_does_not_verify(): void
    {
        Http::fake(['*' => Http::response(AubPayFake::toXml([
            'status' => '0',
            'result_code' => '0',
            'out_trade_no' => 'ORDER_100001',
            'pay_url' => 'https://evil.test/pay',
            'sign' => 'DEADBEEF',
        ]))]);

        $this->expectException(AubApiException::class);
        $this->expectExceptionMessageMatches('/signature .* could not be verified/i');

        AubPay::wallet()->charge($this->charge());
    }

    #[DataProvider('tradeStates')]
    public function test_query_maps_trade_state_onto_the_shared_outcomes(string $state, bool $paid, bool $pending): void
    {
        Http::fake(['*' => AubPayFake::walletResponse([
            'out_trade_no' => 'ORDER_100001',
            'transaction_id' => 'W-1',
            'trade_state' => $state,
            'total_fee' => '9200',
            'trade_type' => 'pay.gcash.webpay',
        ], $this->signature())]);

        $transaction = AubPay::wallet()->query('ORDER_100001');

        $this->assertSame($paid, $transaction->isPaid(), "{$state} paid");
        $this->assertSame($pending, $transaction->isPending(), "{$state} pending");
        $this->assertSame('W-1', $transaction->referencedId);
    }

    public static function tradeStates(): array
    {
        return [
            'success' => ['SUCCESS', true, false],
            // Money did change hands; a refunded order is not an unpaid one.
            'refund' => ['REFUND', true, false],
            'not paid yet' => ['NOTPAY', false, true],
            'customer is paying' => ['USERPAYING', false, true],
            'closed' => ['CLOSED', false, false],
            'pay error' => ['PAYERROR', false, false],
            // An unrecognised state must never be read as failed: this gateway family adds states,
            // and guessing "failed" would cancel orders that were actually paid.
            'unknown future state' => ['SOMETHING_NEW', false, true],
        ];
    }

    public function test_a_query_needs_at_least_one_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AubPay::wallet()->query();
    }

    public function test_it_enforces_the_tighter_out_trade_no_rules_of_this_rail(): void
    {
        // 5-32 characters here, against the card rail's 64 — an id valid for a cashier order can
        // be rejected by this gateway.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/5-32 characters/');

        new WalletCharge(WalletService::GcashWeb, 'ABC', 100, 'body');
    }

    public function test_it_rejects_an_out_trade_no_with_disallowed_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/letters, digits and underscores/');

        new WalletCharge(WalletService::GcashWeb, 'ORDER-100001', 100, 'body');
    }

    public function test_refund_records_are_reassembled_from_their_indexed_fields(): void
    {
        // The format forbids nested nodes, so repeated records arrive as name_0, name_1, ...
        $refunds = \Prycegas\AubPay\Wallet\WalletClient::refundsFrom([
            'refund_count' => '2',
            'refund_status_0' => 'SUCCESS',
            'refund_fee_0' => '100',
            'refund_status_1' => 'PROCESSING',
            'refund_fee_1' => '250',
        ]);

        $this->assertCount(2, $refunds);
        $this->assertSame(['refund_status' => 'SUCCESS', 'refund_fee' => '100'], $refunds[0]);
        $this->assertSame('PROCESSING', $refunds[1]['refund_status']);
    }

    public function test_the_wallet_rail_refuses_to_run_when_disabled(): void
    {
        config(['aub-pay.wallet.enabled' => false]);
        $this->app->forgetInstance(\Prycegas\AubPay\AubPayManager::class);

        $this->expectException(\Prycegas\AubPay\Exceptions\ConfigurationException::class);
        $this->expectExceptionMessageMatches('/aub-pay.wallet.enabled/');

        AubPay::wallet();
    }
}
