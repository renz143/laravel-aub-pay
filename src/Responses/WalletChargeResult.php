<?php

namespace Prycegas\AubPay\Responses;

/**
 * An accepted wallet charge.
 *
 * Which field to use depends on the service you called: a `.webpay` service returns `payUrl` to
 * redirect to, a `.native` one returns `codeUrl` (the raw QR payload, to render yourself) and
 * often `codeImageUrl` (a hosted PNG). `target()` picks whichever is present so simple checkouts
 * do not have to branch.
 */
class WalletChargeResult
{
    public function __construct(
        public readonly string $outTradeNo,
        public readonly ?string $transactionId = null,
        public readonly ?string $payUrl = null,
        public readonly ?string $codeUrl = null,
        public readonly ?string $codeImageUrl = null,
        public readonly ?int $totalFee = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromFields(array $fields): self
    {
        return new self(
            outTradeNo: (string) ($fields['out_trade_no'] ?? ''),
            transactionId: $fields['transaction_id'] ?? null,
            payUrl: $fields['pay_url'] ?? null,
            codeUrl: $fields['code_url'] ?? null,
            codeImageUrl: $fields['code_img_url'] ?? null,
            totalFee: isset($fields['total_fee']) ? (int) $fields['total_fee'] : null,
            raw: $fields,
        );
    }

    /**
     * Where to send the customer — a redirect URL, or the QR payload to render.
     */
    public function target(): ?string
    {
        return $this->payUrl ?? $this->codeUrl;
    }
}
