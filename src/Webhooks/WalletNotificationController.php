<?php

namespace Prycegas\AubPay\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Enums\TransactionResult;
use Prycegas\AubPay\Events\NotificationReceived;
use Prycegas\AubPay\Events\PaymentFailed;
use Prycegas\AubPay\Events\PaymentPending;
use Prycegas\AubPay\Events\PaymentSucceeded;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Responses\Transaction;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleXMLElement;

/**
 * Receives the wallet/QR payment notification (API spec §6).
 *
 * **This rail differs from the Cashier one in the two ways that matter most**, so it does not
 * simply reuse that controller:
 *
 *   1. **The notification is signed.** It carries `sign` and `sign_type`, computed exactly as a
 *      request is, so unlike the Cashier notification it can actually be authenticated. That makes
 *      a fast path legitimate: a notification whose signature verifies is evidence, and the
 *      outcome is read straight off it.
 *   2. **There is a five-second budget.** §6.1: a response that is not the string `success`, *or
 *      that arrives after 5 seconds*, is treated as a failed delivery. A gateway round-trip inside
 *      the request would put that budget at risk, which is the practical reason the fast path
 *      exists rather than always re-querying.
 *
 * An unsigned or mis-signed notification falls back to asking the gateway, so a delivery is never
 * acted on unverified either way.
 *
 * **Retries are aggressive**: 0/15/15/30/180/1800/1800/1800/1800/3600 seconds, for up to three
 * hours. The same notification will therefore arrive several times in normal operation — §6.1 says
 * so outright — so listeners must be idempotent.
 *
 * One check this cannot do for you: §11 requires verifying that `out_trade_no` **and `total_fee`**
 * match your own record before releasing goods. The order id is checked here by resolving it; the
 * amount is yours to compare, in the PaymentSucceeded listener, against what you expected to be
 * paid. A signature proves the message is authentic, not that it is about the order you think.
 */
class WalletNotificationController
{
    private const ACKNOWLEDGED = 'success';

    private const RETRY = 'fail';

    public function __construct(private readonly AubPayManager $manager)
    {
    }

    public function __invoke(Request $request): Response
    {
        $fields = $this->parse($request->getContent());
        $orderId = $fields['out_trade_no'] ?? null;

        NotificationReceived::dispatch($fields, $orderId, 'wallet');

        if (blank($orderId)) {
            $this->logger()->warning('AUB wallet notification carried no out_trade_no.', [
                'keys' => array_keys($fields),
            ]);

            // Nothing a retry can fix: acknowledge so the three-hour retry cycle stops.
            return $this->reply(self::ACKNOWLEDGED);
        }

        try {
            $transaction = $this->resolve($fields, $orderId);
        } catch (AubApiException $e) {
            $this->logger()->warning('AUB wallet notification could not be confirmed.', [
                'out_trade_no' => $orderId,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            // Transient — let it be re-delivered rather than acknowledge a payment never verified.
            return $this->reply(self::RETRY, 500);
        }

        match (true) {
            $transaction->isPaid() => PaymentSucceeded::dispatch($transaction, 'wallet'),
            $transaction->isPending() => PaymentPending::dispatch($transaction, 'wallet'),
            default => PaymentFailed::dispatch($transaction, 'wallet'),
        };

        return $this->reply(self::ACKNOWLEDGED);
    }

    /**
     * Trust a verified signature; otherwise ask the gateway.
     */
    private function resolve(array $fields, string $orderId): Transaction
    {
        if ($this->manager->parameterSignature()->verify($fields)) {
            return $this->fromNotification($fields, $orderId);
        }

        $this->logger()->notice('AUB wallet notification signature did not verify; confirming by query instead.', [
            'out_trade_no' => $orderId,
            'had_sign' => filled($fields['sign'] ?? null),
        ]);

        return $this->manager->wallet()->query($orderId);
    }

    /**
     * Read the outcome off a notification whose signature has already verified.
     *
     * The notification reports success through `result_code` (and `pay_result` alongside it) rather
     * than the `trade_state` a query returns — same rail, different field, which is exactly the
     * sort of thing that makes one shared parser a bad idea.
     */
    private function fromNotification(array $fields, string $orderId): Transaction
    {
        $paid = ($fields['result_code'] ?? null) === '0'
            && (! isset($fields['pay_result']) || $fields['pay_result'] === '0');

        return new Transaction(
            orderId: $orderId,
            result: $paid ? TransactionResult::Success : TransactionResult::Failed,
            referencedId: $fields['transaction_id'] ?? null,
            amount: isset($fields['total_fee']) ? (int) $fields['total_fee'] : null,
            currency: $fields['fee_type'] ?? null,
            paymentType: $fields['trade_type'] ?? null,
            attach: $fields['attach'] ?? null,
            raw: $fields,
        );
    }

    private function parse(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return [];
        }

        return array_map(static fn ($value) => is_scalar($value) ? (string) $value : $value, (array) $xml);
    }

    private function logger(): LoggerInterface
    {
        $channel = $this->manager->config('log_channel');

        return $channel ? Log::channel($channel) : (Log::getFacadeRoot() ?? new NullLogger());
    }

    private function reply(string $body, int $status = 200): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/plain']);
    }
}
