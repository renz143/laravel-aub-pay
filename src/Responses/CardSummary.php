<?php

namespace Prycegas\AubPay\Responses;

/**
 * The masked card AUB reports a payment was made with. Never a full PAN — BIN and last four only,
 * which is all that is safe to store and all that is needed to render "Visa •••• 0000".
 */
class CardSummary
{
    public function __construct(
        public readonly ?string $brand = null,
        public readonly ?string $bin = null,
        public readonly ?string $last4 = null,
    ) {
    }

    public static function fromArray(?array $card): ?self
    {
        if (blank($card)) {
            return null;
        }

        return new self(
            $card['paymentBrand'] ?? null,
            $card['cardBin'] ?? null,
            $card['last4Digits'] ?? null,
        );
    }

    public function label(): string
    {
        return trim(($this->brand ?? 'Card') . ' ' . ($this->last4 ? '•••• ' . $this->last4 : ''));
    }
}
