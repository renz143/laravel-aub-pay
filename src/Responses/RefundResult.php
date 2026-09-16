<?php

namespace Prycegas\AubPay\Responses;

use Prycegas\AubPay\Enums\TransactionResult;

/**
 * The outcome of a refund (API guide §5.3.4).
 *
 * `orderId` here is the refund's *own* serial number, not the original order's — a refund is its
 * own transaction on this gateway. The order being refunded is named by `originalReferencedId`.
 */
class RefundResult
{
    public function __construct(
        public readonly ?string $referencedId = null,
        public readonly ?string $originalReferencedId = null,
        public readonly ?string $originalOrderId = null,
        public readonly ?string $orderId = null,
        public readonly ?int $amount = null,
        public readonly ?TransactionResult $result = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromEnvelope(array $envelope): self
    {
        $order = (array) data_get($envelope, 'data.orderInformation', []);

        return new self(
            referencedId: $order['referencedId'] ?? null,
            originalReferencedId: $order['originalReferencedId'] ?? null,
            originalOrderId: $order['originalOrderId'] ?? null,
            orderId: $order['orderId'] ?? null,
            amount: isset($order['amount']) ? (int) $order['amount'] : null,
            result: TransactionResult::tryFrom((string) ($order['transactionResult'] ?? '')),
            raw: $envelope,
        );
    }

    public function isSettled(): bool
    {
        return $this->result?->isPaid() ?? false;
    }
}
