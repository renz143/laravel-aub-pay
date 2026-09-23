<?php

namespace Prycegas\AubPay\Checkout;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Events\AubPaymentEvent;
use Prycegas\AubPay\Events\CheckoutSessionOverpaid;
use Prycegas\AubPay\Events\CheckoutSessionPaid;
use Prycegas\AubPay\Events\PaymentFailed;
use Prycegas\AubPay\Events\PaymentSucceeded;
use Prycegas\AubPay\Responses\Transaction;
use Psr\Log\LoggerInterface;

/**
 * Settles checkout attempts from the package's own payment events.
 *
 * Listening rather than being called is the point: a signed wallet notification, a confirmed cashier
 * one, and the page asking AUB directly all end in PaymentSucceeded or PaymentFailed, so a checkout
 * is settled by one piece of code however the news arrived. Every event reaching here has already
 * been confirmed with the gateway — see Webhooks\ConfirmsByInquiry.
 *
 * Events about orders that are not checkout attempts are ignored, so this runs alongside whatever
 * listeners the application has for its own orders.
 */
class SettleCheckoutAttempt
{
    public function __construct(private readonly AubPayManager $manager)
    {
    }

    public function handle(AubPaymentEvent $event): void
    {
        if (! str_starts_with($event->orderId(), CheckoutAttempt::ORDER_PREFIX)) {
            return;
        }

        $attempt = CheckoutAttempt::query()
            ->where('order_id', $event->orderId())
            ->where('rail', $event->rail)
            ->first();

        if ($attempt === null) {
            return;
        }

        match (true) {
            $event instanceof PaymentSucceeded => $this->paid($attempt, $event->transaction),
            $event instanceof PaymentFailed => $this->failed($attempt),
            default => null,
        };
    }

    private function paid(CheckoutAttempt $attempt, Transaction $transaction): void
    {
        // A confirmed payment proves money moved, not that it was the amount asked for (§11).
        if ($transaction->amount !== $attempt->amount) {
            $this->logger()->critical('AUB confirmed a checkout payment for the wrong amount; the session was not marked paid.', [
                'checkout_session_id' => $attempt->checkout_session_id,
                'order_id' => $attempt->order_id,
                'expected' => $attempt->amount,
                'paid' => $transaction->amount,
            ]);

            return;
        }

        // Locked, because AUB re-delivers and the page polls: the same payment can arrive twice at
        // once, and two attempts on one session can clear together. Exactly one of them may be the
        // payment that settles the session.
        $settled = DB::transaction(function () use ($attempt, $transaction) {
            $session = CheckoutSession::query()->lockForUpdate()->findOrFail($attempt->checkout_session_id);
            $attempt = CheckoutAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());

            if ($attempt->isPaid()) {
                return null;
            }

            $attempt->update([
                'status' => CheckoutAttempt::PAID,
                'paid_at' => $transaction->respondedAt ?? now(),
                'gateway_reference' => $transaction->referencedId ?? $attempt->gateway_reference,
            ]);

            if ($session->isPaid()) {
                return [CheckoutSessionOverpaid::class, $session, $attempt];
            }

            $session->update([
                'status' => CheckoutSession::PAID,
                'paid_at' => $attempt->paid_at,
                'payment_method' => $attempt->method,
            ]);

            return [CheckoutSessionPaid::class, $session, $attempt];
        });

        if ($settled === null) {
            return;
        }

        [$event, $session, $attempt] = $settled;

        if ($event === CheckoutSessionOverpaid::class) {
            $this->logger()->critical('A checkout session was paid twice; the second payment needs refunding.', [
                'checkout_session_id' => $session->id,
                'order_id' => $attempt->order_id,
                'rail' => $attempt->rail,
                'referenced_id' => $transaction->referencedId,
            ]);
        }

        $event::dispatch($session, $attempt, $transaction);
    }

    /**
     * Only a pending attempt fails. A paid one stays paid whatever arrives after it: the gateway has
     * already said yes, and a late or replayed "no" does not undo a payment.
     */
    private function failed(CheckoutAttempt $attempt): void
    {
        CheckoutAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', CheckoutAttempt::PENDING)
            ->update(['status' => CheckoutAttempt::FAILED]);
    }

    private function logger(): LoggerInterface
    {
        $channel = $this->manager->config('log_channel');

        return $channel ? Log::channel($channel) : Log::getFacadeRoot();
    }
}
