<?php

namespace Prycegas\AubPay\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Responses\Transaction;
use Psr\Log\LoggerInterface;

/**
 * Receives AUB's cashier payment notification (API guide §5.4).
 *
 * Two rules govern everything here:
 *
 *   1. **The body must be the literal string `SUCCESS`** (§5.4.3) — not JSON, not a status code
 *      alone. Anything else reads to AUB as a failed delivery and it re-posts. That retry is
 *      useful, so it is used deliberately: a notification that could not be *confirmed* answers
 *      `FAIL` and gets re-delivered, while an outcome no retry can change (unknown order, payment
 *      genuinely pending or failed) is acknowledged so the retries stop.
 *   2. **The notification is a trigger, not evidence** — see ConfirmsByInquiry.
 *
 * Nothing here touches your database. It confirms the outcome and fires PaymentSucceeded /
 * PaymentFailed / PaymentPending; the listener is where your order lives.
 */
class CashierNotificationController
{
    use ConfirmsByInquiry;

    private const ACKNOWLEDGED = 'SUCCESS';

    private const RETRY = 'FAIL';

    public function __construct(protected readonly AubPayManager $manager)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $this->payload($request);

        try {
            $this->confirm($this->orderIdFrom($payload), $payload, 'cashier');
        } catch (AubApiException $e) {
            // Transient: we could not establish what happened. Ask AUB to try again rather than
            // acknowledging a payment we never verified.
            return $this->reply(self::RETRY, 500);
        }

        return $this->reply(self::ACKNOWLEDGED);
    }

    /**
     * AUB posts JSON, but a gateway that has been seen to send form-encoded bodies on at least one
     * endpoint is worth being tolerant of — an unparsed notification would otherwise retry forever.
     */
    protected function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : $request->all();
    }

    /**
     * §5.4.2 nests the order under `data.orderInformation`, but the flatter shapes are accepted too
     * — the same field has appeared at the top level in the wild, and guessing wrong here means
     * failing to resolve a payment that did happen.
     */
    protected function orderIdFrom(array $payload): ?string
    {
        foreach (['data.orderInformation.orderId', 'orderInformation.orderId', 'orderId'] as $path) {
            $value = data_get($payload, $path);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function inquire(string $orderId): Transaction
    {
        return $this->manager->cashier()->inquire($orderId);
    }

    protected function logger(): LoggerInterface
    {
        $channel = $this->manager->config('log_channel');

        return $channel ? Log::channel($channel) : Log::getFacadeRoot();
    }

    private function reply(string $body, int $status = 200): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/plain']);
    }
}
