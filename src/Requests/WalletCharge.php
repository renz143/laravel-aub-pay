<?php

namespace Prycegas\AubPay\Requests;

use InvalidArgumentException;
use Prycegas\AubPay\Enums\WalletService;

/**
 * A wallet/QR charge (API spec §5.1.3).
 *
 * Note how little this shares with CashierOrder, despite describing the same act: the field is
 * `out_trade_no` not `orderId`, `total_fee` not `amount`, `body` not `goodsDetail`. The names are
 * the gateway's; the mapping is done here so callers only meet them once.
 *
 * Two constraints the gateway enforces and this catches early:
 *
 *   - `out_trade_no` is **5–32 characters**, letters/digits/underscore, case-sensitive. Much
 *     tighter than the card rail's 64, so an id that works for a cashier order may not work here.
 *   - `mch_create_ip` is required. It is the IP of the machine making the call, not the customer's.
 */
class WalletCharge
{
    public function __construct(
        public readonly WalletService $service,
        public readonly string $outTradeNo,
        public readonly int $totalFee,
        public readonly string $body,
        public readonly ?string $mchCreateIp = null,
        public readonly ?string $attach = null,
        public readonly ?string $notifyUrl = null,
        public readonly ?string $callbackUrl = null,
        public readonly ?string $timeStart = null,
        public readonly ?string $timeExpire = null,
        public readonly ?string $deviceInfo = null,
        public readonly ?string $opUserId = null,
        public readonly ?string $goodsTag = null,
        public readonly ?string $productId = null,
        public readonly ?string $limitCreditPay = null,
    ) {
        $length = mb_strlen($outTradeNo);

        if ($length < 5 || $length > 32) {
            throw new InvalidArgumentException(
                "The AUB wallet gateway requires out_trade_no to be 5-32 characters; got {$length}. "
                . 'Note this is tighter than the card rail, which allows 64.'
            );
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $outTradeNo)) {
            throw new InvalidArgumentException(
                "out_trade_no may only contain letters, digits and underscores; got `{$outTradeNo}`."
            );
        }

        if ($totalFee <= 0) {
            throw new InvalidArgumentException("A wallet charge needs a positive total_fee in minor units; got {$totalFee}.");
        }

        if (mb_strlen($body) > 127) {
            throw new InvalidArgumentException('The wallet gateway limits `body` to 127 characters.');
        }

        if ($attach !== null && mb_strlen($attach) > 127) {
            throw new InvalidArgumentException('The wallet gateway limits `attach` to 127 characters.');
        }
    }

    public function toArray(array $defaults = []): array
    {
        return array_filter([
            'service' => $this->service->value,
            'mch_id' => $defaults['mch_id'] ?? null,
            'out_trade_no' => $this->outTradeNo,
            'total_fee' => $this->totalFee,
            'body' => $this->body,
            'attach' => $this->attach,
            // Falls back to the server's own address: the field is mandatory and a wrong-but-present
            // value is accepted, whereas an absent one is rejected outright.
            'mch_create_ip' => $this->mchCreateIp ?? ($defaults['mch_create_ip'] ?? '127.0.0.1'),
            'notify_url' => $this->notifyUrl ?? ($defaults['notify_url'] ?? null),
            'callback_url' => $this->callbackUrl ?? ($defaults['callback_url'] ?? null),
            'time_start' => $this->timeStart,
            'time_expire' => $this->timeExpire,
            'device_info' => $this->deviceInfo,
            'op_user_id' => $this->opUserId,
            'goods_tag' => $this->goodsTag,
            'product_id' => $this->productId,
            'limit_credit_pay' => $this->limitCreditPay,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
