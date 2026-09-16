<?php

namespace Prycegas\AubPay\Events;

/**
 * The gateway has confirmed the order is paid. This is the event to fulfil on.
 *
 * It can fire more than once for the same order: AUB re-posts a notification it did not get a
 * clean acknowledgement for, and a customer may also hit a "check payment" button. Make the
 * listener idempotent — key it on the order and ignore an order already marked paid.
 */
class PaymentSucceeded extends AubPaymentEvent
{
}
