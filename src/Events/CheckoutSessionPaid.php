<?php

namespace Prycegas\AubPay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prycegas\AubPay\Checkout\CheckoutAttempt;
use Prycegas\AubPay\Checkout\CheckoutSession;
use Prycegas\AubPay\Responses\Transaction;

/**
 * A checkout session has been paid. This is the event to fulfil a checkout order on.
 *
 * Fired once per session: the paying attempt is settled under a lock, and redeliveries of the same
 * payment stop there. The amount has already been checked against the session's — but the session
 * only knows what *you* told it, so match `$session->reference_number` or `$session->metadata`
 * against your own order before shipping anything.
 *
 * `$attempt->method` says how it was paid (`qrph`, `card`); `$transaction` is AUB's confirmed
 * account of the payment, with its own reference in `referencedId`.
 */
class CheckoutSessionPaid
{
    use Dispatchable;

    public function __construct(
        public readonly CheckoutSession $session,
        public readonly CheckoutAttempt $attempt,
        public readonly Transaction $transaction,
    ) {
    }
}
