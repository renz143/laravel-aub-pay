<?php

namespace Prycegas\AubPay\Webhooks;

use Prycegas\AubPay\Events\NotificationReceived;
use Prycegas\AubPay\Events\PaymentFailed;
use Prycegas\AubPay\Events\PaymentPending;
use Prycegas\AubPay\Events\PaymentSucceeded;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Responses\Transaction;
use Psr\Log\LoggerInterface;

/**
 * Turn an untrusted notification into a confirmed outcome.
 *
 * **Why this indirection exists.** AUB's notification (§5.4) lists its fields but never mentions a
 * signature, and the vendor's own reference implementation verifies responses only — so there is
 * nothing on the notification that proves it came from AUB. The endpoint is also necessarily
 * public: AUB posts server-to-server and carries no credential you could check. Anyone who learns
 * the URL can post to it.
 *
 * So the notification is read for exactly one thing — *which order to look at* — and the question
 * of whether that order was paid is answered by calling the gateway back. That response **is**
 * signed, and its signature is verified before the package believes it. A forged notification
 * therefore buys an attacker one inquiry call against their own made-up order id, and nothing else.
 *
 * The second benefit is that payment clearing has exactly one implementation. A "check payment"
 * button and an incoming notification arrive at the same events by the same route, so they cannot
 * drift apart.
 *
 * If AUB ever confirms in writing that notifications are signed, verifying the signature here
 * becomes a cheap fast path — but it does not replace the inquiry, because a signature only proves
 * the message is authentic, not that it is current.
 */
trait ConfirmsByInquiry
{
    /**
     * @return Transaction|null null when the gateway could not be reached or the order is unknown,
     *                          which the caller must treat as "ask me again", not as "not paid"
     */
    protected function confirm(?string $orderId, array $payload, string $rail = 'cashier'): ?Transaction
    {
        NotificationReceived::dispatch($payload, $orderId, $rail);

        if (blank($orderId)) {
            $this->logger()->warning('AUB notification carried no order id; nothing to resolve it against.', [
                'rail' => $rail,
                'keys' => array_keys($payload),
            ]);

            return null;
        }

        try {
            $transaction = $this->inquire($orderId);
        } catch (AubApiException $e) {
            // Distinguish "AUB says this order does not exist" from "we could not ask". The first
            // is final and acknowledging it stops a pointless retry loop; the second is transient
            // and must be retried, or a real payment is lost. isIndeterminate() covers timeouts
            // and unknown-result codes, which are the ones worth another delivery.
            $this->logger()->warning('AUB notification could not be confirmed by inquiry.', [
                'rail' => $rail,
                'order_id' => $orderId,
                'code' => $e->errorCode,
                'request_id' => $e->requestId,
                'message' => $e->getMessage(),
            ]);

            if ($e->isIndeterminate() || $e->isConfigurationFault()) {
                throw $e;
            }

            return null;
        }

        $this->dispatchOutcome($transaction, $rail);

        return $transaction;
    }

    private function dispatchOutcome(Transaction $transaction, string $rail): void
    {
        match (true) {
            $transaction->isPaid() => PaymentSucceeded::dispatch($transaction, $rail),
            $transaction->isPending() => PaymentPending::dispatch($transaction, $rail),
            default => PaymentFailed::dispatch($transaction, $rail),
        };
    }

    abstract protected function inquire(string $orderId): Transaction;

    abstract protected function logger(): LoggerInterface;
}
