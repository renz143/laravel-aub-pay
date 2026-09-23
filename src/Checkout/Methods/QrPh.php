<?php

namespace Prycegas\AubPay\Checkout\Methods;

use Illuminate\Support\Str;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Checkout\CheckoutAttempt;
use Prycegas\AubPay\Checkout\CheckoutSession;
use Prycegas\AubPay\Checkout\CheckoutUrls;
use Prycegas\AubPay\Checkout\PaymentMethod;
use Prycegas\AubPay\Enums\WalletService;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Requests\WalletCharge;
use Prycegas\AubPay\Responses\Transaction;

/**
 * The wallet button: QR Ph, through the wallet rail's `pay.instapay.native.v2`.
 *
 * QR Ph is the national QR standard, so one code can be paid from any Philippine bank or e-wallet
 * app, GCash and Maya included — which is why it stands for "wallet" on the page rather than a
 * button per app.
 *
 * Two things about the service shape how the checkout uses it (§5.4, §11.8):
 *
 *   - **It cannot be closed or refunded through the API.** An issued code stays payable until its
 *     `expiration_date`, so that date is the only thing stopping a customer who switched to card
 *     from also paying a stale code. It is set to the attempt's own expiry, which is short and
 *     never outlives the session.
 *   - **It is looked up by `invoiceId`,** which the charge returns and the query requires.
 */
class QrPh implements PaymentMethod
{
    public function __construct(
        private readonly AubPayManager $manager,
        private readonly CheckoutUrls $urls,
    ) {
    }

    public function key(): string
    {
        return 'qrph';
    }

    public function rail(): string
    {
        return 'wallet';
    }

    public function label(): string
    {
        return 'QR Ph';
    }

    public function open(CheckoutSession $session, CheckoutAttempt $attempt): void
    {
        $result = $this->manager->wallet()->charge(new WalletCharge(
            service: WalletService::InstapayQrV2,
            outTradeNo: $attempt->order_id,
            totalFee: $attempt->amount,
            body: Str::limit($session->summary(), 127, ''),
            attach: $session->id,
            notifyUrl: $this->urls->notify('wallet'),
            expirationDate: $attempt->expires_at,
        ));

        if (blank($result->codeUrl) && blank($result->codeImageUrl)) {
            throw new AubApiException('AUB accepted the QR Ph charge but returned no code to show.', null, null, $result->raw);
        }

        $attempt->fill([
            'invoice_id' => $result->invoiceId,
            'gateway_reference' => $result->transactionId,
            'qr_code' => $result->codeUrl,
            'qr_image_url' => $result->codeImageUrl,
            // AUB's deadline wins over ours when it sends one: it is the one the banks enforce.
            'expires_at' => $result->expiresAt ?? $attempt->expires_at,
        ]);
    }

    public function inquire(CheckoutAttempt $attempt): Transaction
    {
        return $this->manager->wallet()->query(outTradeNo: $attempt->order_id, invoiceId: $attempt->invoice_id);
    }
}
