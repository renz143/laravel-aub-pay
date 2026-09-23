<?php

namespace Prycegas\AubPay\Checkout;

use Prycegas\AubPay\Responses\Transaction;

/**
 * One button on the checkout page, and what pressing it does at AUB.
 *
 * This is all the page knows about a method. `open()` is where a rail's own vocabulary stays — an
 * out_trade_no and an InstapayQrV2 charge for QR Ph, an orderId and a cashier order for card — and
 * it leaves behind either a `redirect_url` to send the customer to, or a `qr_code` / `qr_image_url`
 * to show them. The page does whichever it finds.
 *
 * `rail()` must name the rail whose PaymentSucceeded / PaymentFailed events settle this method's
 * attempts, `wallet` or `cashier`: those events are the only way a checkout is ever marked paid.
 *
 * Register an implementation under a key in `aub-pay.checkout.methods` to offer it.
 */
interface PaymentMethod
{
    /**
     * Stable identifier — stored on attempts and posted by the page. `qrph`, `card`.
     */
    public function key(): string;

    /**
     * `wallet` or `cashier`: the `rail` on the payment events that settle this method.
     */
    public function rail(): string;

    public function label(): string;

    /**
     * Open the payment at AUB and fill in the attempt's gateway fields.
     *
     * The attempt arrives saved, with its order id, amount and expiry set; whatever this fills in is
     * saved after it returns. Throw to refuse — the attempt is then marked failed and never offered
     * to the customer again.
     */
    public function open(CheckoutSession $session, CheckoutAttempt $attempt): void;

    /**
     * What AUB says happened to the attempt: a signed answer, verified by the rail before it gets
     * here.
     */
    public function inquire(CheckoutAttempt $attempt): Transaction;
}
