<?php

namespace Prycegas\AubPay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prycegas\AubPay\Responses\Transaction;

/**
 * Base for the three payment outcomes. Carries the confirmed transaction and which rail it came
 * from, so an application serving both card and wallet customers can branch without inspecting
 * payload shapes.
 *
 * Every one of these is dispatched only *after* the outcome has been confirmed with the gateway,
 * never straight off a notification body — see Webhooks\ConfirmsByInquiry.
 */
abstract class AubPaymentEvent
{
    use Dispatchable;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly string $rail = 'cashier',
    ) {
    }

    public function orderId(): string
    {
        return $this->transaction->orderId;
    }
}
