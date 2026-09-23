<?php

namespace Prycegas\AubPay\Responses;

use Carbon\CarbonImmutable;
use Prycegas\AubPay\Requests\WalletCharge;

/**
 * An accepted wallet charge.
 *
 * Which field to use depends on the service you called: a `.webpay` service returns `payUrl` to
 * redirect to, a `.native` one returns `codeUrl` (the raw QR payload, to render yourself) and
 * often `codeImageUrl` (a hosted PNG). `target()` picks whichever is present so simple checkouts
 * do not have to branch.
 *
 * QR Ph (`InstapayQrV2`, §5.4.2) adds two things and leaves two out. It returns an `invoiceId` —
 * store it, because querying the order later requires it — and `expiresAt`, the moment the code
 * stops being payable. It does not echo `out_trade_no` or return a `transaction_id`; the former is
 * filled in from the request by WalletClient::charge(), and the latter arrives with the payment.
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
        public readonly ?string $invoiceId = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $uuid = null,
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
            // §5.4.2 spells it `invoiceId` in this one reply and `invoice_id` everywhere else, the
            // notification and the query included. Read both rather than guess which one is live.
            invoiceId: self::text($fields['invoiceId'] ?? $fields['invoice_id'] ?? null),
            expiresAt: self::gatewayTime($fields['expiration_date'] ?? null),
            uuid: self::text($fields['uuid'] ?? null),
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

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function gatewayTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{14}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('YmdHis', $value, WalletCharge::GATEWAY_TIMEZONE) ?: null;
    }
}
