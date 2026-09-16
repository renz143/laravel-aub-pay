<?php

namespace Prycegas\AubPay\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification arrived and was parsed — dispatched *before* it has been confirmed with the
 * gateway, and therefore carrying nothing you should trust.
 *
 * Useful for logging and for spotting deliveries the handler could not resolve. Do not fulfil
 * orders on this: the payload is unauthenticated and anyone can post it. Listen for
 * PaymentSucceeded instead.
 */
class NotificationReceived
{
    use Dispatchable;

    public function __construct(
        public readonly array $payload,
        public readonly ?string $orderId,
        public readonly string $rail = 'cashier',
    ) {
    }
}
