<?php

namespace Prycegas\AubPay\Responses;

/**
 * A cashier order that AUB has accepted. Redirect the customer to `cashierUrl`.
 *
 * `orderId` is the serial number you supplied, echoed back — store it, because it is what inquiry,
 * refund and the notification are all keyed on. `referencedId` is AUB's own transaction id and is
 * usually absent here: it comes into existence when the payment does, not when the order does.
 */
class CashierSession
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $cashierUrl,
        public readonly ?string $referencedId = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly array $raw = [],
    ) {
    }
}
