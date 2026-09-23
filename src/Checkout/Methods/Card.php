<?php

namespace Prycegas\AubPay\Checkout\Methods;

use Illuminate\Support\Str;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Checkout\CheckoutAttempt;
use Prycegas\AubPay\Checkout\CheckoutSession;
use Prycegas\AubPay\Checkout\CheckoutUrls;
use Prycegas\AubPay\Checkout\PaymentMethod;
use Prycegas\AubPay\Requests\CashierOrder;
use Prycegas\AubPay\Responses\Transaction;

/**
 * The card button: AUB's hosted cashier page, the rail that keeps card numbers off your servers.
 *
 * The customer leaves for AUB's page and comes back to `/{id}/return`, which asks AUB what happened
 * rather than reading anything off the redirect. The order's `validityPeriod` is whatever is left of
 * the attempt, because it is set once, here, and the cashier rail has no way to withdraw an order
 * afterwards.
 */
class Card implements PaymentMethod
{
    public function __construct(
        private readonly AubPayManager $manager,
        private readonly CheckoutUrls $urls,
    ) {
    }

    public function key(): string
    {
        return 'card';
    }

    public function rail(): string
    {
        return 'cashier';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function open(CheckoutSession $session, CheckoutAttempt $attempt): void
    {
        $order = $this->manager->cashier()->createOrder(new CashierOrder(
            orderId: $attempt->order_id,
            amount: $attempt->amount,
            description: Str::limit($session->summary(), 128, ''),
            attach: $session->id,
            callbackUrl: $this->urls->session($session, 'return'),
            notifyUrl: $this->urls->notify('cashier'),
            validityPeriod: max(1, (int) ceil($attempt->secondsRemaining() / 60)),
        ));

        $attempt->fill([
            'redirect_url' => $order->cashierUrl,
            'gateway_reference' => $order->referencedId,
        ]);
    }

    public function inquire(CheckoutAttempt $attempt): Transaction
    {
        return $this->manager->cashier()->inquire($attempt->order_id);
    }
}
