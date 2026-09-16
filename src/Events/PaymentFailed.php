<?php

namespace Prycegas\AubPay\Events;

/**
 * The gateway has confirmed the payment did not go through, and will not on its own.
 *
 * Safe to release stock or cancel on. Distinct from PaymentPending, which is not a failure.
 */
class PaymentFailed extends AubPaymentEvent
{
}
