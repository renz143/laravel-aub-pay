<?php

namespace Prycegas\AubPay\Checkout;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Events\PaymentFailed;
use Prycegas\AubPay\Events\PaymentPending;
use Prycegas\AubPay\Events\PaymentSucceeded;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Requests\CheckoutSessionRequest;
use Prycegas\AubPay\Requests\LineItem;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hosted checkout sessions — `AubPay::checkout()`.
 *
 * For the merchant: `create()` a session, send the customer to `$session->url()`, and fulfil on
 * CheckoutSessionPaid. Everything between is the page's job, and runs through the methods below.
 *
 * **The one hazard this class is built around** is that AUB gives no way to take back a payment
 * request once it is issued: QR Ph has no close and the cashier rail no cancel. Every QR code shown
 * and every cashier page opened stays payable until it lapses. So `start()` reuses a live attempt
 * rather than issuing a second one, attempts are short and never outlive their session, and if a
 * customer manages to pay twice anyway, CheckoutSessionOverpaid says so rather than letting the
 * second payment disappear.
 */
class CheckoutService
{
    /** @var array<string, PaymentMethod> */
    private array $methods = [];

    public function __construct(
        private readonly AubPayManager $manager,
        private readonly CheckoutUrls $urls,
        private readonly Container $container,
    ) {
    }

    /**
     * Open a checkout session. Send the customer to `$session->url()`.
     *
     * Each method's rail is checked here, so a wallet rail that is switched off fails in your own
     * request rather than in front of the customer at their first click.
     */
    public function create(CheckoutSessionRequest $request): CheckoutSession
    {
        foreach ($request->methods() as $key) {
            $this->assertRailAvailable($this->method($key));
        }

        return CheckoutSession::create([
            'id' => CheckoutSession::newId(),
            'reference_number' => $request->referenceNumber,
            'status' => CheckoutSession::ACTIVE,
            'currency' => 'PHP',
            'amount' => $request->amount(),
            'line_items' => array_map(static fn (LineItem $item) => $item->toArray(), $request->lineItems),
            'description' => $request->description,
            'billing' => $request->billing?->toArray() ?: null,
            'payment_methods' => $request->methods(),
            'metadata' => $request->metadata ?: null,
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'expires_at' => now()->addMinutes($request->expiresAfter ?? (int) $this->config('expires_after', 60)),
        ]);
    }

    public function find(string $id): ?CheckoutSession
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) ? CheckoutSession::query()->find($id) : null;
    }

    /**
     * Stop the page taking new payments — PayMongo's "expire session".
     *
     * With one difference worth knowing: a QR code or cashier page the customer already has stays
     * payable until its own expiry, because AUB offers no way to withdraw either. A payment that
     * lands anyway still settles the session, since the money did move, and your CheckoutSessionPaid
     * listener decides what to do about it.
     */
    public function expire(CheckoutSession|string $session): CheckoutSession
    {
        $session = $session instanceof CheckoutSession ? $session : CheckoutSession::query()->findOrFail($session);

        if ($session->status === CheckoutSession::ACTIVE) {
            $session->update(['status' => CheckoutSession::EXPIRED, 'expires_at' => now()]);
        }

        return $session;
    }

    public function url(CheckoutSession|string $session): string
    {
        return $this->urls->session($session);
    }

    public function method(string $key): PaymentMethod
    {
        if (isset($this->methods[$key])) {
            return $this->methods[$key];
        }

        $class = $this->config('methods', [])[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("No checkout payment method is registered as `{$key}` (aub-pay.checkout.methods).");
        }

        $method = $this->container->make($class);

        if (! $method instanceof PaymentMethod) {
            throw new InvalidArgumentException("`{$class}` must implement " . PaymentMethod::class . '.');
        }

        return $this->methods[$key] = $method;
    }

    /**
     * The session's methods that are still registered, in the order the session lists them.
     *
     * @return array<string, PaymentMethod>
     */
    public function methodsFor(CheckoutSession $session): array
    {
        $registered = $this->config('methods', []);
        $methods = [];

        foreach ((array) $session->payment_methods as $key) {
            if (isset($registered[$key])) {
                $methods[$key] = $this->method($key);
            }
        }

        return $methods;
    }

    /**
     * Open a payment with the chosen method — or hand back the one already open.
     *
     * A live attempt for the same method is returned as it is: a double-click, a reload or a customer
     * coming back from "choose another method" sees the same QR code, and AUB is not asked for a
     * second one that would stay payable alongside the first.
     *
     * A new attempt is saved *before* AUB is called. If the call times out, AUB may have opened the
     * order anyway, and a payment against it still needs an attempt to settle to — so the attempt
     * is kept, marked failed so it is never offered again, and the error goes back to the caller.
     *
     * Serialised per session with a cache lock, which makes the check-then-create safe.
     *
     * @return CheckoutAttempt|null null when the session can no longer take payments
     *
     * @throws AubApiException when AUB refuses to open the payment
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException when another request holds the session
     */
    public function start(CheckoutSession $session, string $methodKey): ?CheckoutAttempt
    {
        $method = $this->method($methodKey);

        return Cache::lock("aub-pay:checkout:{$session->getKey()}", 60)->block(15, function () use ($session, $method) {
            $session->refresh();

            if (! $session->isPayable()) {
                return null;
            }

            $live = $session->attempts()
                ->where('method', $method->key())
                ->where('status', CheckoutAttempt::PENDING)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            if ($live !== null) {
                return $live;
            }

            $expiresAt = now()->addMinutes((int) $this->config('attempt_lifetime', 15));

            if ($session->expires_at !== null && $session->expires_at->lt($expiresAt)) {
                $expiresAt = $session->expires_at;
            }

            $attempt = $session->attempts()->create([
                'method' => $method->key(),
                'rail' => $method->rail(),
                'order_id' => CheckoutAttempt::newOrderId($session),
                'amount' => $session->amount,
                'status' => CheckoutAttempt::PENDING,
                'expires_at' => $expiresAt,
            ]);

            try {
                $method->open($session, $attempt);
            } catch (Throwable $e) {
                $attempt->forceFill(['status' => CheckoutAttempt::FAILED])->save();

                throw $e;
            }

            $attempt->save();

            return $attempt;
        });
    }

    /**
     * Ask AUB about the session's unsettled attempts, and let its answers settle them.
     *
     * The notification is how a payment normally arrives; this is for when it has not yet — a
     * customer back from the cashier page before AUB has posted, or a notify URL AUB cannot reach.
     * Answers go out as the rail's own PaymentSucceeded / PaymentFailed / PaymentPending events, so a
     * payment found here and one announced by a notification are settled by the same listener.
     *
     * Limited to one call per attempt per `inquiry_interval`, because the page polls this on the
     * customer's behalf. `$force` lifts the limit for the one moment it matters: the return from
     * the cashier page.
     */
    public function refresh(CheckoutSession $session, bool $force = false): CheckoutSession
    {
        if ($session->isPaid()) {
            return $session;
        }

        $interval = (int) $this->config('inquiry_interval', 15);
        $cutoff = now()->subSeconds($interval);

        $attempts = $session->attempts()->where('status', CheckoutAttempt::PENDING)->get();

        foreach ($attempts as $attempt) {
            // A brand-new attempt is certainly unpaid, and one checked a moment ago has not changed.
            if (! $force && ($attempt->created_at?->gt($cutoff) || $attempt->last_checked_at?->gt($cutoff))) {
                continue;
            }

            $attempt->forceFill(['last_checked_at' => now()])->save();

            $this->inquire($attempt);
        }

        return $session->refresh();
    }

    private function inquire(CheckoutAttempt $attempt): void
    {
        try {
            $transaction = $this->method($attempt->method)->inquire($attempt);
        } catch (AubApiException $e) {
            // Not an outcome — the order may simply not exist at AUB yet. Asked again next time.
            $this->logger()->info('AUB could not confirm a checkout attempt.', [
                'order_id' => $attempt->order_id,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($transaction->orderId !== $attempt->order_id) {
            $this->logger()->warning('AUB answered a checkout inquiry about a different order.', [
                'asked' => $attempt->order_id,
                'answered' => $transaction->orderId,
            ]);

            return;
        }

        match (true) {
            $transaction->isPaid() => PaymentSucceeded::dispatch($transaction, $attempt->rail),
            $transaction->isPending() => PaymentPending::dispatch($transaction, $attempt->rail),
            default => PaymentFailed::dispatch($transaction, $attempt->rail),
        };
    }

    private function assertRailAvailable(PaymentMethod $method): void
    {
        // Building the rail is the check: a disabled one throws ConfigurationException.
        match ($method->rail()) {
            'wallet' => $this->manager->wallet(),
            'cashier' => $this->manager->cashier(),
            default => null,
        };
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->manager->config("checkout.{$key}") ?? $default;
    }

    private function logger(): LoggerInterface
    {
        $channel = $this->manager->config('log_channel');

        return $channel ? Log::channel($channel) : Log::getFacadeRoot();
    }
}
