<?php

namespace Prycegas\AubPay\Responses;

use Carbon\CarbonImmutable;
use Prycegas\AubPay\Enums\TransactionResult;

/**
 * What AUB knows about an order — the shape returned by inquiry, and the shape a notification
 * carries. One class for both, because they describe the same thing and callers should not have to
 * care which one told them.
 *
 * `isPaid()` is the question almost every caller is really asking, and it is deliberately strict:
 * only `SUCCESS` counts. `PENDING` means AUB does not know yet, which is not the same as "no" —
 * see TransactionResult.
 */
class Transaction
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?TransactionResult $result = null,
        public readonly ?string $referencedId = null,
        public readonly ?string $originalReferencedId = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $paymentType = null,
        public readonly ?CarbonImmutable $respondedAt = null,
        public readonly ?string $attach = null,
        public readonly ?string $description = null,
        public readonly ?CardSummary $card = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromEnvelope(array $envelope): self
    {
        $order = (array) data_get($envelope, 'data.orderInformation', []);

        return new self(
            orderId: (string) ($order['orderId'] ?? ''),
            result: TransactionResult::tryFrom((string) ($order['transactionResult'] ?? '')),
            referencedId: $order['referencedId'] ?? null,
            originalReferencedId: $order['originalReferencedId'] ?? null,
            amount: isset($order['amount']) ? (int) $order['amount'] : null,
            currency: $order['currency'] ?? null,
            paymentType: $order['paymentType'] ?? null,
            respondedAt: self::parseDate($order['responseDate'] ?? null),
            attach: $order['attach'] ?? null,
            description: $order['goodsDetail'] ?? null,
            // §5.4 puts paymentBrand directly on orderInformation for notifications while §5.2 puts
            // a whole card object beside it. Read both so one class covers both messages.
            card: CardSummary::fromArray((array) data_get($envelope, 'data.card') ?: array_filter([
                'paymentBrand' => $order['paymentBrand'] ?? null,
            ])),
            raw: $envelope,
        );
    }

    public function isPaid(): bool
    {
        return $this->result?->isPaid() ?? false;
    }

    public function isPending(): bool
    {
        return $this->result === TransactionResult::Pending;
    }

    /**
     * Decode an `attach` that was written as JSON. Returns an empty array for anything else, since
     * `attach` is a free-form string and plenty of integrations put a plain id in it.
     */
    public function attachData(): array
    {
        $decoded = json_decode((string) $this->attach, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function parseDate(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
