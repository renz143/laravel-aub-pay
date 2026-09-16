<?php

namespace Prycegas\AubPay\Wallet;

use InvalidArgumentException;
use Prycegas\AubPay\Enums\TransactionResult;
use Prycegas\AubPay\Enums\WalletService;
use Prycegas\AubPay\Http\XmlTransport;
use Prycegas\AubPay\Requests\WalletCharge;
use Prycegas\AubPay\Responses\Transaction;
use Prycegas\AubPay\Responses\WalletChargeResult;

/**
 * The QR / e-wallet rail: GCash, GrabPay, QRPh, Instapay, Alipay+, WeChat Pay, UnionPay.
 *
 * Structurally unlike the card rails — see XmlTransport for the wire format — and with one extra
 * status layer. `query()` is where that bites: the call can succeed, the *query* can succeed, and
 * the payment still be unpaid. Those are three different fields, and this class collapses them
 * into the same Transaction the Cashier rail returns so callers do not have to learn a second
 * vocabulary.
 */
class WalletClient
{
    public function __construct(
        private readonly XmlTransport $transport,
        private readonly array $defaults = [],
    ) {
    }

    /**
     * Start a payment. Send the customer to `$result->target()`: a redirect URL for a `.webpay`
     * service, or a QR payload to render for a `.native` one.
     */
    public function charge(WalletCharge $charge): WalletChargeResult
    {
        return WalletChargeResult::fromFields(
            $this->transport->post($charge->toArray($this->defaults))
        );
    }

    /**
     * Ask the gateway about an order. Supply the merchant's own reference, or AUB's
     * `transaction_id` — the gateway prefers the latter when both are given.
     */
    public function query(?string $outTradeNo = null, ?string $transactionId = null): Transaction
    {
        if (blank($outTradeNo) && blank($transactionId)) {
            throw new InvalidArgumentException('A wallet query needs either an out_trade_no or a transaction_id.');
        }

        return $this->toTransaction($this->transport->post(array_filter([
            'service' => WalletService::Query->value,
            'mch_id' => $this->defaults['mch_id'] ?? null,
            'out_trade_no' => $outTradeNo,
            'transaction_id' => $transactionId,
        ], static fn ($value) => $value !== null && $value !== '')));
    }

    /**
     * Refund, in full or in part.
     *
     * `$outRefundNo` is the refund's own reference and must be unique — the gateway supports
     * several partial refunds against one payment, and it tells them apart by this field alone.
     * `$totalFee` is the *original* order's amount, not the refund's; the gateway checks the pair.
     *
     * @param  int  $refundFee  amount to refund, in minor units
     * @param  int  $totalFee  the original order total, in minor units
     */
    public function refund(
        string $outRefundNo,
        int $refundFee,
        int $totalFee,
        ?string $outTradeNo = null,
        ?string $transactionId = null,
    ): array {
        if (blank($outTradeNo) && blank($transactionId)) {
            throw new InvalidArgumentException('A wallet refund needs either an out_trade_no or a transaction_id.');
        }

        return $this->transport->post(array_filter([
            'service' => WalletService::Refund->value,
            'mch_id' => $this->defaults['mch_id'] ?? null,
            'out_refund_no' => $outRefundNo,
            'refund_fee' => $refundFee,
            'total_fee' => $totalFee,
            'out_trade_no' => $outTradeNo,
            'transaction_id' => $transactionId,
            'op_user_id' => $this->defaults['mch_id'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Refund history for an order.
     *
     * The response is *indexed rather than nested* — `refund_status_0`, `refund_status_1`, and so
     * on, because the format forbids nested nodes. `refundsFrom()` reassembles them into a list.
     */
    public function refundQuery(?string $outTradeNo = null, ?string $transactionId = null, ?string $outRefundNo = null): array
    {
        return $this->transport->post(array_filter([
            'service' => WalletService::RefundQuery->value,
            'mch_id' => $this->defaults['mch_id'] ?? null,
            'out_trade_no' => $outTradeNo,
            'transaction_id' => $transactionId,
            'out_refund_no' => $outRefundNo,
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Close an unpaid order so it cannot be paid later.
     *
     * Unlike the Cashier rail, this gateway *does* give you a cancel, and you should use it: the
     * spec is explicit that re-initiating payment requires a new order number and the old one
     * closed, or the customer can pay both.
     */
    public function close(string $outTradeNo): array
    {
        return $this->transport->post([
            'service' => WalletService::Close->value,
            'mch_id' => $this->defaults['mch_id'] ?? null,
            'out_trade_no' => $outTradeNo,
        ]);
    }

    /**
     * Flatten the `*_$n` indexed refund records a refundQuery returns into a list of arrays.
     */
    public static function refundsFrom(array $fields): array
    {
        $count = (int) ($fields['refund_count'] ?? 0);
        $refunds = [];

        for ($i = 0; $i < $count; $i++) {
            $record = [];

            foreach ($fields as $key => $value) {
                if (str_ends_with($key, "_{$i}")) {
                    $record[substr($key, 0, -strlen("_{$i}"))] = $value;
                }
            }

            $refunds[] = $record;
        }

        return $refunds;
    }

    /**
     * Map the gateway's `trade_state` onto the same three outcomes the card rail reports.
     *
     * `REFUND` maps to paid on purpose: the money did change hands, and an order that was paid and
     * later refunded is not the same as one that never paid. Callers that care about the
     * difference have the raw value on `$transaction->raw`.
     */
    private function toTransaction(array $fields): Transaction
    {
        $state = strtoupper((string) ($fields['trade_state'] ?? ''));

        $result = match ($state) {
            'SUCCESS', 'REFUND' => TransactionResult::Success,
            'NOTPAY', 'USERPAYING' => TransactionResult::Pending,
            'CLOSED', 'REVOKED', 'PAYERROR' => TransactionResult::Failed,
            // An unrecognised state is treated as pending, never as failed: this gateway family
            // has added states over time, and guessing "failed" on an unknown one would cancel
            // orders that were actually paid.
            default => TransactionResult::Pending,
        };

        return new Transaction(
            orderId: (string) ($fields['out_trade_no'] ?? ''),
            result: $result,
            referencedId: $fields['transaction_id'] ?? null,
            amount: isset($fields['total_fee']) ? (int) $fields['total_fee'] : null,
            currency: $fields['fee_type'] ?? null,
            paymentType: $fields['trade_type'] ?? null,
            attach: $fields['attach'] ?? null,
            raw: $fields,
        );
    }
}
