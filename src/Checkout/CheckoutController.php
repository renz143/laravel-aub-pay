<?php

namespace Prycegas\AubPay\Checkout;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Exceptions\ConfigurationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The hosted checkout page and the handful of requests it makes.
 *
 * Nothing the browser sends here is believed about a payment. The page can ask for a payment to be
 * *opened*, and it can ask what the checkout already knows; whether anything was paid is decided
 * only by the payment events, which come from AUB's notifications or from asking AUB directly.
 */
class CheckoutController
{
    private const ERROR = 'aub_checkout_error';

    private const NOTICE = 'aub_checkout_notice';

    private const DEBUG = 'aub_checkout_debug';

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly CheckoutUrls $urls,
        private readonly AubPayManager $manager,
    ) {
    }

    public function show(CheckoutSession $checkoutSession): SymfonyResponse
    {
        $session = $checkoutSession;

        if ($session->isPaid()) {
            return $this->render('paid', $session, [
                'method' => $this->methodLabel($session->payment_method),
            ]);
        }

        if ($session->isExpired()) {
            return $this->render('expired', $session);
        }

        return $this->render('show', $session, [
            'methods' => $this->checkout->methodsFor($session),
            // An earlier QR code or cashier page may still be paid while the customer sits here,
            // so the page keeps watching for as long as one is live.
            'watching' => $session->attempts()
                ->where('status', CheckoutAttempt::PENDING)
                ->where('expires_at', '>', now())
                ->exists(),
        ]);
    }

    public function pay(Request $request, CheckoutSession $checkoutSession): RedirectResponse
    {
        $session = $checkoutSession;
        $key = (string) $request->input('method');

        if (! in_array($key, $session->payment_methods ?? [], true) || ! array_key_exists($key, $this->checkout->methodsFor($session))) {
            return $this->toPage($session)->with(self::ERROR, 'Choose a payment method to continue.');
        }

        $method = $this->checkout->method($key);

        try {
            $attempt = $this->checkout->start($session, $key);
        } catch (LockTimeoutException) {
            return $this->toPage($session)->with(self::ERROR, 'This checkout is busy in another window. Please try again.');
        } catch (AubApiException|ConfigurationException $e) {
            $this->logger()->warning('AUB would not open a checkout payment.', [
                'checkout_session_id' => $session->id,
                'method' => $key,
                'code' => $e instanceof AubApiException ? $e->errorCode : null,
                'message' => $e->getMessage(),
            ]);

            $redirect = $this->toPage($session)->withInput()->with(
                self::ERROR,
                "We couldn't start your {$method->label()} payment. Please try again, or choose another payment method."
            );

            // The customer gets that sentence and nothing more: a gateway's wording is not for them.
            // With APP_DEBUG on, the page also says what actually happened — a merchant limit, a
            // service AUB has not switched on — instead of leaving it to be dug out of the log.
            return config('app.debug') ? $redirect->with(self::DEBUG, self::reason($e)) : $redirect;
        }

        if ($attempt === null) {
            // Paid or expired since the page was drawn; the page will say which.
            return $this->toPage($session);
        }

        return filled($attempt->redirect_url)
            ? redirect()->away($attempt->redirect_url)
            : redirect()->to($this->urls->session($session, 'qr'));
    }

    public function qr(CheckoutSession $checkoutSession): SymfonyResponse
    {
        $session = $checkoutSession;
        $attempt = $this->latestQrAttempt($session);

        if (! $session->isPayable() || $attempt === null || ! $attempt->isPending()) {
            return $this->toPage($session);
        }

        return $this->render('qr', $session, [
            'attempt' => $attempt,
            'method' => $this->checkout->method($attempt->method),
            'watching' => true,
        ]);
    }

    /**
     * The QR code as a file, for the customer paying from the phone the code is showing on — they
     * cannot scan their own screen, but every banking app can read a saved image.
     *
     * Fetched here rather than linked because the image lives on AUB's host, and a cross-origin link
     * opens the picture instead of saving it.
     */
    public function download(CheckoutSession $checkoutSession): SymfonyResponse
    {
        $session = $checkoutSession;
        $attempt = $this->latestQrAttempt($session);

        abort_unless($attempt !== null && $attempt->isLive() && preg_match('#^https?://#i', (string) $attempt->qr_image_url), 404);

        try {
            $image = Http::timeout(10)->get($attempt->qr_image_url);
        } catch (ConnectionException) {
            abort(502);
        }

        $type = strtolower((string) $image->header('Content-Type'));

        // Only an image passes through, so this can never serve whatever else a URL might return.
        abort_unless($image->successful() && str_starts_with($type, 'image/'), 502);

        $extension = match (true) {
            str_contains($type, 'svg') => 'svg',
            str_contains($type, 'jpeg') => 'jpg',
            default => 'png',
        };

        $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($session->reference_number ?: substr($session->id, 0, 8)));

        return new Response($image->body(), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => "attachment; filename=\"qrph-{$name}.{$extension}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Polled by the page while a payment is open. Asks AUB about unsettled attempts now and then
     * (see CheckoutService::refresh()), and reports only what the checkout has settled.
     */
    public function status(CheckoutSession $checkoutSession): JsonResponse
    {
        $session = $this->checkout->refresh($checkoutSession);

        return (new JsonResponse([
            'status' => match (true) {
                $session->isPaid() => CheckoutSession::PAID,
                $session->isExpired() => CheckoutSession::EXPIRED,
                default => CheckoutSession::ACTIVE,
            },
            'redirect_url' => $session->isPaid() ? $session->success_url : null,
        ]))->header('Cache-Control', 'no-store');
    }

    /**
     * Where AUB's cashier page sends the customer back to.
     *
     * The redirect proves only that the browser came back, so AUB is asked what happened — every
     * unsettled attempt, now, without the polling limit. A customer who paid lands on the success
     * URL; one who was declined sees why they are back; and one AUB has no answer for yet waits on
     * the page, which keeps checking.
     */
    public function return(CheckoutSession $checkoutSession): RedirectResponse
    {
        $session = $this->checkout->refresh($checkoutSession, force: true);

        if ($session->isPaid()) {
            return redirect()->away($session->success_url);
        }

        $attempt = $session->attempts()->whereNotNull('redirect_url')->latest('id')->first();

        if ($attempt?->isFailed()) {
            return $this->toPage($session)->with(
                self::ERROR,
                'Your card payment did not go through. You can try again, or choose another payment method.'
            );
        }

        if ($attempt?->isPending()) {
            return $this->toPage($session)->with(
                self::NOTICE,
                "We're confirming your card payment with the bank. This page will update on its own once it goes through."
            );
        }

        return $this->toPage($session);
    }

    private static function reason(AubApiException|ConfigurationException $e): string
    {
        if ($e instanceof ConfigurationException) {
            return "Configuration: {$e->getMessage()}";
        }

        return 'AUB answered: ' . $e->getMessage() . (filled($e->errorCode) ? " (code {$e->errorCode})" : '');
    }

    private function latestQrAttempt(CheckoutSession $session): ?CheckoutAttempt
    {
        return $session->attempts()
            ->where(fn ($query) => $query->whereNotNull('qr_code')->orWhereNotNull('qr_image_url'))
            ->latest('id')
            ->first();
    }

    private function toPage(CheckoutSession $session): RedirectResponse
    {
        return redirect()->to($this->urls->session($session));
    }

    private function methodLabel(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        try {
            return $this->checkout->method($key)->label();
        } catch (\InvalidArgumentException) {
            return $key;
        }
    }

    /**
     * Every page shares the header, the order summary and the theme, so they are assembled once.
     *
     * The headers are for a page that shows a customer's details at a secret URL: never cached, never
     * framed, and never leaking its own address to the image hosts it loads from.
     */
    private function render(string $view, CheckoutSession $session, array $data = []): SymfonyResponse
    {
        $config = (array) $this->manager->config('checkout');

        return response()->view("aub-pay::checkout.{$view}", $data + [
            'session' => $session,
            'urls' => $this->urls,
            'merchant' => [
                'name' => ($config['merchant']['name'] ?? null) ?: config('app.name'),
                'logo_url' => $config['merchant']['logo_url'] ?? null,
                'privacy_url' => $config['merchant']['privacy_url'] ?? null,
            ],
            'theme' => [
                'header' => self::color($config['theme']['header'] ?? null, '#144037'),
                'accent' => self::color($config['theme']['accent'] ?? null, '#15a349'),
                'accent_text' => self::color($config['theme']['accent_text'] ?? null, '#11272b'),
            ],
            'error' => session(self::ERROR),
            'notice' => session(self::NOTICE),
            'debug' => session(self::DEBUG),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Theme colours are written into a style attribute, so only a plain hex colour is let through.
     */
    private static function color(?string $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : $default;
    }

    private function logger(): \Psr\Log\LoggerInterface
    {
        $channel = $this->manager->config('log_channel');

        return $channel ? Log::channel($channel) : Log::getFacadeRoot();
    }
}
