<?php

namespace Prycegas\AubPay\Enums;

/**
 * The `service` field, which is how the wallet gateway routes a call — one endpoint, many
 * operations, selected by this string.
 *
 * The `native` / `webpay` distinction is the one that matters when choosing:
 *
 *   - **native** returns a `code_url` (a QR payload) and usually a `code_img_url`. The customer
 *     scans it. Use for in-store, or for showing a QR on a desktop checkout.
 *   - **webpay** returns a `pay_url` to redirect the customer's browser to, which hands off to the
 *     wallet's own app or web flow. Use for mobile checkout.
 *
 * Which of these a merchant may call is provisioned per-merchant by AUB. Calling one you are not
 * enabled for comes back as a routing or payment-type error, not as a permissions message.
 *
 * `InstapayQrV2` is **QR Ph** (§5.4), the national standard any Philippine bank or e-wallet app can
 * scan, and it breaks this family's pattern in three places: its expiry is `expiration_date` rather
 * than `time_expire`, its reply names the order by an `invoiceId` instead of echoing
 * `out_trade_no`, and it is queried through `InstapayQuery` with that invoice id. It also cannot be
 * closed or refunded through this API (§11.8), so an issued code stays payable until it expires.
 */
enum WalletService: string
{
    case GcashQr = 'pay.gcash.native';
    case GcashWeb = 'pay.gcash.webpay';
    case GrabPayQr = 'pay.grab.native';
    case GrabPayWeb = 'pay.grab.webpay';
    case InstapayQr = 'pay.instapay.native';
    case InstapayQrV2 = 'pay.instapay.native.v2';
    case AlipayQr = 'pay.alipay.native';
    case AlipayQrIntl = 'pay.alipay.native.intl';
    case WeChatQr = 'pay.weixin.native';
    case WeChatQrIntl = 'pay.weixin.native.intl';
    case UnionPayQr = 'pay.upi.native.intl';

    case Query = 'unified.trade.query';

    /**
     * The Instapay lookup (§5.3.3, §5.4.3), keyed on the `invoice_id` a QR Ph or Instapay charge
     * returned — a field the unified query has no slot for.
     */
    case InstapayQuery = 'pay.instapay.query';

    case Refund = 'unified.trade.refund';
    case RefundQuery = 'unified.trade.refundquery';
    case Close = 'unified.trade.close';

    /**
     * True when the response carries a `pay_url` to redirect to rather than a `code_url` to render.
     */
    public function isRedirect(): bool
    {
        return str_ends_with($this->value, '.webpay');
    }

    public function isQr(): bool
    {
        return str_contains($this->value, '.native');
    }
}
