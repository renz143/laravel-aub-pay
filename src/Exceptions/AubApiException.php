<?php

namespace Prycegas\AubPay\Exceptions;

use Prycegas\AubPay\Enums\ResponseCode;
use RuntimeException;

/**
 * The gateway answered, and the answer was not a success — a declined card, a rejected signature,
 * a malformed request.
 *
 * `$errorCode` is AUB's own response code (API guide §6.1), which is what their support will ask
 * for; `$requestId` is the `Customer-Request-Id` we sent, which is how they find it. Log both.
 */
class AubApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly array $response = [],
    ) {
        parent::__construct($message);
    }

    public function code(): ?ResponseCode
    {
        return $this->errorCode === null ? null : ResponseCode::tryFrom($this->errorCode);
    }

    /**
     * Ours to fix, not the customer's — a bad key, a wrong merchant id, an unconfigured payment
     * type. Worth alerting on separately from an ordinary decline, which is business as usual.
     */
    public function isConfigurationFault(): bool
    {
        return $this->code()?->isConfigurationFault() ?? false;
    }

    /**
     * The transaction's outcome is genuinely unknown — a timeout or an unknown-result code. The
     * caller must re-inquire rather than assume either way, because the customer may have been
     * charged.
     */
    public function isIndeterminate(): bool
    {
        return $this->code()?->isIndeterminate() ?? false;
    }
}
