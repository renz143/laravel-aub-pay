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
