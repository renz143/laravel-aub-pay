<?php

namespace Prycegas\AubPay\Cashier;

use Illuminate\Support\Facades\Log;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Http\JsonTransport;
use Prycegas\AubPay\Requests\CashierOrder;
use Prycegas\AubPay\Responses\CashierSession;
use Prycegas\AubPay\Responses\RefundResult;
use Prycegas\AubPay\Responses\Transaction;

/**
 * The Card Cashier rail: AUB hosts the payment page, you redirect to it.
 *
 * This is the rail to use unless you have a specific reason not to. No card data reaches your
 * servers, which keeps you in PCI-DSS SAQ-A, and it is the only one of AUB's three APIs that has
 * been exercised in production here.
 *
 * The flow has three steps and one trap:
 *
 *   1. `createOrder()` — hand AUB an order, get a `cashierUrl`, redirect the customer to it.
 *   2. AUB POSTs a notification to your `notifyUrl` when the customer pays.
 *   3. `inquire()` — ask AUB what actually happened.
 *
 * The trap is that step 3 is not optional. The notification in step 2 carries no signature AUB
 * will vouch for, so it tells you *which* order to look at and nothing trustworthy about whether
 * it was paid. The shipped webhook controller handles this correctly; if you write your own, see
 * Webhooks\ConfirmsByInquiry for what it has to do.
 */
class CashierClient
{
    public function __construct(
        private readonly JsonTransport $transport,
        private readonly array $defaults = [],
    ) {
    }

    /**
     * Open a cashier order and get back the hosted page to redirect to.
     */
    public function createOrder(CashierOrder $order): CashierSession
    {
        $envelope = $this->transport->post('/cashier/v1/payment', $order->toArray($this->defaults));

        $cashierUrl = data_get($envelope, 'data.cashierUrl');

        if (blank($cashierUrl)) {
            // A success code with no URL is not something the caller can act on, and redirecting to
            // an empty string would show the customer a broken page rather than an error.
            throw new AubApiException(
                'AUB accepted the cashier order but returned no cashierUrl to redirect to.',
                data_get($envelope, 'code'),
                null,
                $envelope,
            );
        }

        $information = (array) data_get($envelope, 'data.orderInformation', []);

        return new CashierSession(
            orderId: $order->orderId,
            cashierUrl: $cashierUrl,
            referencedId: $information['referencedId'] ?? null,
            amount: isset($information['amount']) ? (int) $information['amount'] : $order->amount,
            currency: $information['currency'] ?? null,
            raw: $envelope,
        );
    }

    /**
     * Ask the gateway what actually happened to an order.
     *
     * This is the authoritative answer: the response is signed by AUB and the signature is
     * verified before you see it. Use it to confirm a notification, and to back a "check payment"
     * button for customers who closed the tab.
     */
    public function inquire(string $orderId): Transaction
    {
        return Transaction::fromEnvelope($this->transport->post('/cashier/v1/inquiry', [
            'orderInformation' => ['orderId' => $orderId],
        ]));
    }

    /**
     * Refund a settled order, in full or in part.
     *
     * A refund is its own transaction and needs its own serial number, distinct from the original
     * order's — pass one as `$refundOrderId`, or let it default to the original prefixed with
     * `R-`. The order being refunded is named by AUB's `referencedId`, which you only have after
     * payment: read it off `inquire()`, not off the cashier session.
     *
     * @param  string  $referencedId  AUB's id for the payment being refunded
     * @param  int  $amount  in minor units
     */
    public function refund(string $orderId, string $referencedId, int $amount, ?string $refundOrderId = null): RefundResult
    {
        return RefundResult::fromEnvelope($this->transport->post('/cashier/v1/refund', [
            'orderInformation' => [
                'amount' => $amount,
                'orderId' => mb_substr($refundOrderId ?? ('R-' . $orderId), 0, 64),
                'originalReferencedId' => $referencedId,
            ],
        ]));
    }

    /**
     * There is no cancel or expire endpoint on this API, and pretending otherwise would be worse
     * than saying so.
     *
     * An abandoned cashier order stays payable until its own `validityPeriod` runs out, which is
     * set once at creation and cannot be changed afterwards. The practical consequence: a customer
     * who cancels an order and then finds the old payment tab can still pay it. Keep
     * `aub-pay.validity_period` at or below whatever window your application prunes unpaid orders
     * on, so the two expire together.
     */
    public function expire(string $orderId): void
    {
        Log::info('AUB has no expire-checkout endpoint; the order will lapse on its own validityPeriod.', [
            'order_id' => $orderId,
            'validity_period_minutes' => $this->defaults['validity_period'] ?? null,
        ]);
    }
}
