<?php

namespace Prycegas\AubPay\Events;

/**
 * The gateway does not yet know the outcome (transactionResult PENDING).
 *
 * **Not a failure.** The customer may be mid-3DS challenge, or the wallet may still be settling.
 * Do not cancel the order on this event; wait for a later notification, or inquire again.
 */
class PaymentPending extends AubPaymentEvent
{
}
