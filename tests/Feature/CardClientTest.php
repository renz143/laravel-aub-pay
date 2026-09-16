<?php

namespace Prycegas\AubPay\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Exceptions\ConfigurationException;
use Prycegas\AubPay\Facades\AubPay;
use Prycegas\AubPay\Requests\BillingInformation;
use Prycegas\AubPay\Requests\BrowserInformation;
use Prycegas\AubPay\Requests\CardDetails;
use Prycegas\AubPay\Requests\CardPayment;
use Prycegas\AubPay\Requests\CustomerInformation;
use Prycegas\AubPay\Testing\AubPayFake;
use Prycegas\AubPay\Tests\TestCase;

/**
 * The direct card rail. Note this rail is disabled by default, and these tests have to turn it on
 * explicitly — enabling it is a PCI-DSS scope decision, so it cannot happen by accident.
 */
class CardClientTest extends TestCase
{
    /** A throwaway RSA public key standing in for AUB's encryption key. */
    private string $jwePublicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->jwePublicKey = (string) preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($keypair)['key']);

        config([
            'aub-pay.card.enabled' => true,
            'aub-pay.card.jwe_public_key' => $this->jwePublicKey,
        ]);

        $this->app->forgetInstance(AubPayManager::class);
    }

    private function card(): CardDetails
    {
        return new CardDetails('5491320330720068', '3', '2027', '372');
    }

    private function payment(?string $redirectUrl = null): CardPayment
    {
        return new CardPayment(
            orderId: 'CARD-1',
            amount: 9200,
            card: $this->card(),
            customer: new CustomerInformation('Juan', 'Dela Cruz'),
            redirectUrl: $redirectUrl,
        );
    }

    public function test_the_rail_is_off_unless_explicitly_enabled(): void
    {
        config(['aub-pay.card.enabled' => false]);
        $this->app->forgetInstance(AubPayManager::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/aub-pay.card.enabled/');

        AubPay::card();
    }

    public function test_it_encrypts_the_pan_and_security_code_and_sends_the_expiry_in_clear(): void
    {
        Http::fake(['*' => AubPayFake::success([
            'orderInformation' => ['orderId' => 'CARD-1', 'transactionResult' => 'SUCCESS'],
        ], $this->signer())]);

        AubPay::card()->pay($this->payment());

        Http::assertSent(function (Request $request) {
            $card = json_decode($request->body(), true)['card'];

            // The PAN must never appear on the wire in clear.
            $this->assertStringNotContainsString('5491320330720068', $request->body());
            $this->assertStringNotContainsString('"372"', $request->body());

            // Both encrypted fields are compact JWEs.
            foreach (['pan', 'securityCode'] as $field) {
                $this->assertCount(5, explode('.', $card[$field]), "{$field} should be a compact JWE");
                $header = json_decode((string) base64_decode(strtr(explode('.', $card[$field])[0], '-_', '+/')), true);
                $this->assertSame('RSA-OAEP-256', $header['alg']);
                $this->assertSame('A256CBC-HS512', $header['enc']);
            }

            // The expiry travels in clear — the gateway's design, not ours. Month is zero-padded.
            $this->assertSame('03', $card['expirationMonth']);
            $this->assertSame('2027', $card['expirationYear']);

            return true;
        });
    }

    public function test_a_plain_payment_goes_to_the_non_3ds_endpoint(): void
    {
        Http::fake(['*' => AubPayFake::success(['orderInformation' => ['orderId' => 'CARD-1']], $this->signer())]);

        AubPay::card()->pay($this->payment());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/online/v1/payment'));
    }

    public function test_a_redirect_url_routes_the_payment_through_3d_secure(): void
    {
        Http::fake(['*' => AubPayFake::success(['orderInformation' => ['orderId' => 'CARD-1']], $this->signer())]);

        AubPay::card()->pay($this->payment('https://shop.test/3ds/return'));

        Http::assertSent(function (Request $r) {
            $this->assertStringEndsWith('/online/v1/threeds/payment', $r->url());
            $this->assertSame('https://shop.test/3ds/return', json_decode($r->body(), true)['redirectUrl']);

            return true;
        });
    }

    public function test_preauthorization_picks_the_matching_3ds_endpoint(): void
    {
        Http::fake(['*' => AubPayFake::success(['orderInformation' => ['orderId' => 'CARD-1']], $this->signer())]);

        AubPay::card()->preauthorize($this->payment('https://shop.test/3ds/return'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/online/v1/threeds/preauthorization'));
    }

    public function test_it_sends_the_browser_fingerprint_3ds_v2_requires(): void
    {
        Http::fake(['*' => AubPayFake::success(['orderInformation' => ['orderId' => 'CARD-1']], $this->signer())]);

        AubPay::card()->pay(new CardPayment(
            orderId: 'CARD-2',
            amount: 100,
            card: $this->card(),
            customer: new CustomerInformation('Juan', 'Dela Cruz', '09171234567', browser: new BrowserInformation(
                javascriptEnabled: true,
                userAgent: 'Mozilla/5.0',
                language: 'en-PH',
                ip: '112.198.1.1',
                javaEnabled: false,
                screenWidth: 1200,
                screenHeight: 800,
                timezone: -480,
            )),
            billing: new BillingInformation(street1: '1 Ayala Ave', city: 'Makati', country: 'PH', postcode: '1226'),
            redirectUrl: 'https://shop.test/3ds/return',
        ));

        Http::assertSent(function (Request $r) {
            $body = json_decode($r->body(), true);
            $browser = $body['customerInformation']['browserInformation'];

            $this->assertTrue($browser['javascriptEnabled']);
            // false must survive: "absent" does not mean the same thing to the issuer as "false".
            $this->assertFalse($browser['javaEnabled']);
            $this->assertSame(1200, $browser['screenWidth']);
            $this->assertSame(-480, $browser['timezone']);
            $this->assertSame('PH', $body['billingInformation']['country']);

            return true;
        });
    }

    public function test_capture_reverse_and_unfreeze_reference_the_original_transaction(): void
    {
        foreach ([
            'capture' => '/online/v1/capture',
            'reverse' => '/online/v1/reverse',
            'unfreeze' => '/online/v1/unfreeze',
        ] as $method => $path) {
            Http::fake(['*' => AubPayFake::success([
                'orderInformation' => ['orderId' => 'CAP-1', 'transactionResult' => 'SUCCESS'],
            ], $this->signer())]);

            AubPay::card()->{$method}('CAP-1', 'W-ORIGINAL', 9200);

            Http::assertSent(function (Request $r) use ($path) {
                if (! str_ends_with($r->url(), $path)) {
                    return false;
                }

                $order = json_decode($r->body(), true)['orderInformation'];
                $this->assertSame('W-ORIGINAL', $order['originalReferencedId']);
                $this->assertSame(9200, $order['amount']);

                return true;
            });
        }
    }

    public function test_the_card_number_is_kept_out_of_debug_output(): void
    {
        $details = print_r($this->card(), true);

        $this->assertStringNotContainsString('5491320330720068', $details);
        $this->assertStringContainsString('****0068', $details);
    }

    public function test_it_validates_card_details_before_anything_reaches_the_network(): void
    {
        foreach ([
            'letters in the pan' => ['4111-1111-1111-1111', '3', '2027', '123'],
            'month out of range' => ['4111111111111111', '13', '2027', '123'],
            'two digit year' => ['4111111111111111', '3', '27', '123'],
            'five digit cvv' => ['4111111111111111', '3', '2027', '12345'],
        ] as $case => $arguments) {
            try {
                new CardDetails(...$arguments);
                $this->fail("Expected `{$case}` to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
