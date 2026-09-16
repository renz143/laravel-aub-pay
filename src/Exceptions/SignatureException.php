<?php

namespace Prycegas\AubPay\Exceptions;

use RuntimeException;

/**
 * A key could not be read, or signing failed outright — a configuration fault, not a rejected
 * payment. Distinct from AubApiException, which carries a response the gateway actually returned.
 */
class SignatureException extends RuntimeException
{
}
