<?php

namespace Prycegas\AubPay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prycegas\AubPay\Checkout\CheckoutAttempt;
use Prycegas\AubPay\Checkout\CheckoutSession;
use Prycegas\AubPay\Responses\Transaction;

/**
 * A second payment has cleared on a session that was already paid — the customer scanned an old QR
 * code after paying by card, say.
 *
 * The checkout makes this unlikely (live attempts are reused and short-lived) but cannot rule it
 * out, because AUB offers no way to withdraw a QR code or a cashier order once issued. The money is
 * real and the order has already been paid once, so this payment needs refunding. QR Ph has no
 * refund API (§11.8), so a QR Ph attempt is refunded through AUB directly; a card attempt can go
 * through `AubPay::cashier()->refund($attempt->order_id, $transaction->referencedId, $attempt->amount)`.
 *
 * Logged at `critical` as well, for anyone not listening yet.
 */
class CheckoutSessionOverpaid
{
    use Dispatchable;

    public function __construct(
        public readonly CheckoutSession $session,
        public readonly CheckoutAttempt $attempt,
        public readonly Transaction $transaction,
    ) {
    }
}
