<?php

namespace Prycegas\AubPay\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Prycegas\AubPay\Checkout\CheckoutAttempt;
use Prycegas\AubPay\Checkout\CheckoutSession;
use Prycegas\AubPay\Facades\AubPay;
use Prycegas\AubPay\Requests\CheckoutBilling;
use Prycegas\AubPay\Requests\CheckoutSessionRequest;
use Prycegas\AubPay\Requests\LineItem;

/**
 * The hosted checkout, switched on, with a database under it. Kept apart from the base TestCase so
 * the rail tests keep running exactly as a checkout-less installation would.
 */
abstract class CheckoutTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The page runs behind `web`, whose cookie encryption needs a key.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('aub-pay.checkout', array_merge((require __DIR__ . '/../config/aub-pay.php')['checkout'], [
            'enabled' => true,
            'url' => $this->checkoutUrl(),
            'merchant' => ['name' => 'PRYCEGAS', 'logo_url' => null, 'privacy_url' => 'https://prycegas.test/privacy'],
            'middleware' => ['web'],
        ]));
    }

    protected function checkoutUrl(): ?string
    {
        return 'https://checkout.prycegas.com';
    }

    /**
     * The order in the PayMongo screenshot this checkout is modelled on: two 11 kg refills and a
     * delivery fee, ₱2,750.00 in all.
     */
    protected function openSession(array $overrides = []): CheckoutSession
    {
        return AubPay::checkout()->create(new CheckoutSessionRequest(...$overrides + [
            'lineItems' => [
                new LineItem('11 kg LPG Content', 135000, quantity: 2, description: '11kg LPG cylinder for cooking and heating.'),
                new LineItem('Delivery Fee', 5000, description: 'Delivery Fee', imageUrl: 'https://cdn.prycegas.test/delivery.png'),
            ],
            'successUrl' => 'https://prycegas.test/orders/1/paid',
            'cancelUrl' => 'https://prycegas.test/cart',
            'referenceNumber' => 'PG-0a1b2c3d4e5f6',
            'description' => 'WVO1, Juan Dela Cruz, LPG11C 2x, false',
            'billing' => new CheckoutBilling(name: 'Juan Dela Cruz', email: 'juan@example.com'),
        ]));
    }

    /**
     * An attempt as the page would have left it, without going through a gateway to get there.
     */
    protected function attempt(CheckoutSession $session, string $method = 'qrph', array $attributes = []): CheckoutAttempt
    {
        return $session->attempts()->create($attributes + [
            'method' => $method,
            'rail' => $method === 'card' ? 'cashier' : 'wallet',
            'order_id' => CheckoutAttempt::newOrderId($session),
            'amount' => $session->amount,
            'status' => CheckoutAttempt::PENDING,
            'invoice_id' => $method === 'qrph' ? '1234567890123456' : null,
            'qr_code' => $method === 'qrph' ? '00020101021228760011ph.ppmi.p2m' : null,
            'qr_image_url' => $method === 'qrph' ? 'https://pay.wepayez.com/pay/qrcode?uuid=abc' : null,
            'redirect_url' => $method === 'card' ? 'https://cashier.wepayez.com/h5/abc' : null,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    protected static function xml(string $body): array
    {
        return array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : $value,
            (array) simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA),
        );
    }
}
