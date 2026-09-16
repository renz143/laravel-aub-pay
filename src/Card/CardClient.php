<?php

namespace Prycegas\AubPay\Card;

use Prycegas\AubPay\Crypto\JweEncrypter;
use Prycegas\AubPay\Http\JsonTransport;
use Prycegas\AubPay\Requests\CardPayment;
use Prycegas\AubPay\Responses\RefundResult;
use Prycegas\AubPay\Responses\Transaction;

/**
 * The direct card API (`/online/v1/*`) — you collect the card, you send it to AUB.
 *
 * ⚠ **Read this before using it.** Raw PANs pass through your servers, which takes you out of
 * PCI-DSS SAQ-A and into SAQ-D: quarterly scans, annual penetration testing, and a far larger set
 * of controls over every system the card data touches. The Cashier rail does the same job without
 * any of that, because AUB hosts the form. Use this rail only when you have a requirement the
 * hosted page genuinely cannot meet, and have accepted the compliance work that follows.
 *
 * It is also the least-proven rail here: the Cashier rail has run in production, this one has been
 * built from the specification and the vendor's Java reference implementation. Exercise it against
 * UAT before trusting it.
 *
 * **The 3-D Secure flow is asynchronous and does not end with the response to `pay()`.** When a
 * payment carries a `redirectUrl`, an approval may come back as code `01` — "result unknown, wait
 * for the notification and inquire again" — with a URL to send the cardholder to for the
 * challenge. The final outcome arrives by notification, and must be confirmed with `inquire()`.
 * Treating a `01` as a failure charges customers and then cancels their orders.
 */
class CardClient
{
    public function __construct(
        private readonly JsonTransport $transport,
        private readonly JweEncrypter $encrypter,
        private readonly array $defaults = [],
    ) {
    }

    /**
     * Charge a card. Routes to the 3-D Secure endpoint when the payment carries a `redirectUrl`.
     */
    public function pay(CardPayment $payment): Transaction
    {
        return Transaction::fromEnvelope($this->transport->post(
            $payment->usesThreeDs() ? '/online/v1/threeds/payment' : '/online/v1/payment',
            $payment->toArray($this->encrypter, $this->defaults),
        ));
    }

    /**
     * Reserve funds without taking them. Complete with `capture()`, or release with `unfreeze()`.
     */
    public function preauthorize(CardPayment $payment): Transaction
    {
        return Transaction::fromEnvelope($this->transport->post(
            $payment->usesThreeDs() ? '/online/v1/threeds/preauthorization' : '/online/v1/preauthorization',
            $payment->toArray($this->encrypter, $this->defaults),
        ));
    }

    /**
     * Take funds previously reserved by `preauthorize()`. May be for less than was reserved.
     *
     * `$referencedId` is AUB's id for the pre-authorization, and `$orderId` is a **new** serial
     * number for the capture — these are separate transactions to the gateway.
     */
    public function capture(string $orderId, string $referencedId, int $amount): Transaction
    {
        return $this->reference('/online/v1/capture', $orderId, $referencedId, $amount);
    }

    /**
     * Void a payment that has not yet settled. After settlement, `refund()` is the only option —
     * which one applies depends on how long ago the payment was, so callers generally try reverse
     * first and fall back.
     */
    public function reverse(string $orderId, string $referencedId, int $amount): Transaction
    {
        return $this->reference('/online/v1/reverse', $orderId, $referencedId, $amount);
    }

    /**
     * Release funds reserved by `preauthorize()` without taking them.
     */
    public function unfreeze(string $orderId, string $referencedId, int $amount): Transaction
    {
        return $this->reference('/online/v1/unfreeze', $orderId, $referencedId, $amount);
    }

    /**
     * Return funds from a settled payment, in full or in part.
     */
    public function refund(string $orderId, string $referencedId, int $amount): RefundResult
    {
        return RefundResult::fromEnvelope($this->transport->post('/online/v1/refund', [
            'orderInformation' => [
                'amount' => $amount,
                'orderId' => mb_substr($orderId, 0, 64),
                'originalReferencedId' => $referencedId,
            ],
        ]));
    }

    /**
     * Ask what happened to an order — the authoritative answer, and the required follow-up to any
     * 3-D Secure payment that came back as code `01`.
     */
    public function inquire(string $orderId): Transaction
    {
        return Transaction::fromEnvelope($this->transport->post('/online/v1/inquiry', [
            'orderInformation' => ['orderId' => $orderId],
        ]));
    }

    private function reference(string $path, string $orderId, string $referencedId, int $amount): Transaction
    {
        return Transaction::fromEnvelope($this->transport->post($path, [
            'orderInformation' => [
                'amount' => $amount,
                'orderId' => mb_substr($orderId, 0, 64),
                'originalReferencedId' => $referencedId,
            ],
        ]));
    }
}
