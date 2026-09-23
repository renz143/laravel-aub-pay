<?php

namespace Prycegas\AubPay\Tests\Feature;

use Prycegas\AubPay\Tests\CheckoutTestCase;

/**
 * Without `aub-pay.checkout.url` the checkout lives under a path on the app's own host — the local
 * development setup, and the one an app without a checkout subdomain runs.
 */
class CheckoutPathTest extends CheckoutTestCase
{
    protected function checkoutUrl(): ?string
    {
        return null;
    }

    public function test_the_page_is_served_under_the_configured_path(): void
    {
        $session = $this->openSession();

        $this->assertSame("http://localhost/checkout/{$session->id}", $session->url());
        $this->get("/checkout/{$session->id}")->assertOk()->assertSee('Complete Your Order');
    }

    public function test_the_hex_constraint_keeps_it_off_the_apps_own_routes(): void
    {
        $this->get('/checkout/return')->assertNotFound();
    }
}
