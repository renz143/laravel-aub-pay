<?php

namespace Prycegas\AubPay\Requests;

use InvalidArgumentException;

/**
 * A cashier order (API guide §5.1.3) — what you hand AUB to get a hosted payment page back.
 *
 * **`amount` is in minor units.** ₱92.00 is `9200`, never `92.00`. Every amount on every AUB rail
 * works this way, and the gateway will happily charge ₱0.92 if you get it wrong.
 *
 * **`orderId` is yours, and it matters more than it looks.** AUB has no session id of its own to
 * give you at this stage; this serial number *is* the handle for the order, the thing inquiry and
 * refund are keyed on, and the only identifier the notification carries that you can resolve
 * against your own records. It must be unique per order and at most 64 characters.
 *
 * The field lengths below are the gateway's, and they are enforced here rather than truncated
 * silently: a quietly shortened `orderId` is an order you cannot look up afterwards.
 */
class CashierOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
        public readonly ?string $description = null,
        public readonly ?string $attach = null,
        public readonly ?string $callbackUrl = null,
        public readonly ?string $notifyUrl = null,
        public readonly ?int $validityPeriod = null,
        public readonly ?int $orderAmount = null,
        public readonly ?int $tipFee = null,
        public readonly ?int $surcharge = null,
        public readonly ?string $service = null,
        public readonly ?string $operatorId = null,
        public readonly ?string $operatorName = null,
    ) {
        $this->assertLength('orderId', $orderId, 64);
        $this->assertLength('description', $description, 128);
        $this->assertLength('attach', $attach, 128);
        $this->assertLength('callbackUrl', $callbackUrl, 2048);
        $this->assertLength('notifyUrl', $notifyUrl, 2048);
        $this->assertLength('service', $service, 128);
        $this->assertLength('operatorId', $operatorId, 32);
        $this->assertLength('operatorName', $operatorName, 32);

        if ($orderId === '') {
            throw new InvalidArgumentException('An AUB cashier order needs an orderId: it is the only handle you get on the order.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException("An AUB cashier order needs a positive amount in minor units; got {$amount}.");
        }
    }

    /**
     * `goodsDetail` is the gateway's name for what everyone else calls a description; the mapping
     * happens here so callers never have to think in AUB's vocabulary.
     *
     * Nulls and empty strings are stripped rather than sent: the gateway rejects some fields for
     * being present-but-empty (error 09) where it would have accepted them being absent.
     */
    public function toArray(array $defaults = []): array
    {
        return ['orderInformation' => array_filter([
            'amount' => $this->amount,
            'orderId' => $this->orderId,
            'goodsDetail' => $this->description,
            'attach' => $this->attach,
            'callbackUrl' => $this->callbackUrl ?? ($defaults['callback_url'] ?? null),
            'notifyUrl' => $this->notifyUrl ?? ($defaults['notify_url'] ?? null),
            'validityPeriod' => $this->validityPeriod ?? ($defaults['validity_period'] ?? null),
            'orderAmount' => $this->orderAmount,
            'tipFee' => $this->tipFee,
            'surcharge' => $this->surcharge,
            'service' => $this->service,
            'operatorId' => $this->operatorId,
            'operatorName' => $this->operatorName,
        ], static fn ($value) => $value !== null && $value !== '')];
    }

    private function assertLength(string $field, ?string $value, int $max): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            throw new InvalidArgumentException(
                "AUB limits `{$field}` to {$max} characters; got " . mb_strlen($value)
                . '. Shorten it deliberately rather than letting it be cut off.'
            );
        }
    }
}
