<?php

namespace Prycegas\AubPay\Enums;

/**
 * The `code` field on every JSON-rail response (API guide §6.1).
 *
 * Worth having as an enum rather than string comparisons because the codes fall into four groups
 * that callers must treat very differently — and the difference between "the customer's card was
 * declined" and "our key is wrong" is invisible in the raw numbers.
 *
 * Two quirks preserved from the guide:
 *
 *   - **Success is ambiguous.** §5.1.4 and the §6.1 table both say `00`; the refund sample in
 *     §5.3.4 shows `S0000`. Both are accepted, because reading a success as a failure would
 *     strand a customer who has already been charged. Which one the live gateway actually returns
 *     on refund is still open with AUB.
 *   - **`06` is spelled `SGIN ERROR`** in the source table. Kept as documented so a search for
 *     the vendor's wording finds it, with the meaning spelled out in the message.
 */
enum ResponseCode: string
{
    case Success = '00';
    case SuccessAlternate = 'S0000';

    case ThreeDsResultUnknown = '01';
    case MerchantVerificationError = '02';
    case RoutingMisconfigured = '03';
    case PaymentTypeNotConfigured = '04';
    case OrderNotFound = '05';
    case SignatureError = '06';
    case CryptoError = '07';
    case DuplicateReporting = '08';
    case ParameterEmptyOrMalformed = '09';
    case RequestIdExists = '10';
    case ServiceDemotion = '11';
    case RequestTooFrequent = '12';

    case ReferToIssuer = '20';
    case InvalidMerchant = '21';
    case GetCardInformation = '22';
    case DoNotHonor = '23';
    case InvalidTransaction = '24';
    case InvalidAmount = '25';
    case CheckCardNumber = '26';
    case CheckCardNumberDuplicate = '27';
    case LostCard = '28';
    case LockedCard = '29';
    case InsufficientLimit = '30';
    case CardExpired = '31';
    case InvalidPin = '32';
    case NotPermittedToIssuer = '33';
    case NotPermittedToAcquirer = '34';
    case ExceedsWithdrawalAmount = '35';
    case RestrictedCard = '36';
    case SecurityViolation = '37';
    case ExceedsWithdrawalCount = '38';
    case ReferToIssuerAlternate = '39';
    case ModifyPin = '40';
    case PinTriesExceeded = '41';
    case DomesticDebitNotAllowed = '42';
    case UnableToVerifyPin = '43';
    case WrongPin = '44';
    case AuthorizationSystemError = '45';
    case SystemErrorTransactionFailed = '46';
    case DuplicateTransmission = '47';

    case TransactionUnknown = '96';
    case TransactionTimeout = '97';
    case TransactionFailure = '98';
    case SystemError = '99';

    public function isSuccess(): bool
    {
        return $this === self::Success || $this === self::SuccessAlternate;
    }

    /**
     * The outcome is genuinely unknown and **must not be treated as a failure** — the customer may
     * already have been charged. Re-inquire rather than deciding either way.
     */
    public function isIndeterminate(): bool
    {
        return match ($this) {
            self::ThreeDsResultUnknown, self::TransactionUnknown, self::TransactionTimeout => true,
            default => false,
        };
    }

    /**
     * Ours to fix: a wrong key, an unregistered merchant, a payment type AUB has not switched on.
     * These do not vary by customer, so the first one in production means every transaction is
     * failing — worth alerting on separately from a decline.
     */
    public function isConfigurationFault(): bool
    {
        return match ($this) {
            self::MerchantVerificationError,
            self::RoutingMisconfigured,
            self::PaymentTypeNotConfigured,
            self::SignatureError,
            self::CryptoError,
            self::InvalidMerchant => true,
            default => false,
        };
    }

    /**
     * A bug in the request we built — a missing field, a reused `Customer-Request-Id`, an amount
     * the gateway will not accept. Retrying the identical request will fail identically.
     */
    public function isRequestFault(): bool
    {
        return match ($this) {
            self::ParameterEmptyOrMalformed,
            self::RequestIdExists,
            self::DuplicateReporting,
            self::DuplicateTransmission,
            // Sits in the middle of the decline block but is plainly ours: we sent the amount.
            self::InvalidAmount,
            self::InvalidTransaction => true,
            default => false,
        };
    }

    /**
     * The issuer or the card said no. Customer-facing, and normal business — show the message,
     * do not alert.
     *
     * Codes 20-47 are the decline block, but **the block is not homogeneous**: `21 INVALID
     * MERCHANT` is a setup problem, and `24`/`25` are malformed requests, all sitting among
     * genuine card declines. A plain range check therefore reports those as declines *as well as*
     * faults, and an operator filtering on "declines are normal" never sees the one that is not.
     * The other two categories win, which keeps the three mutually exclusive.
     */
    public function isDeclined(): bool
    {
        $numeric = (int) $this->value;

        return $this !== self::SuccessAlternate
            && $numeric >= 20
            && $numeric <= 47
            && ! $this->isConfigurationFault()
            && ! $this->isRequestFault();
    }

    /**
     * Worth trying again later with a fresh request id: the gateway shed load or throttled us.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::ServiceDemotion, self::RequestTooFrequent, self::SystemError => true,
            default => false,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Success, self::SuccessAlternate => 'Approved.',
            self::ThreeDsResultUnknown => '3-D Secure result unknown; wait for the notification and inquire again.',
            self::MerchantVerificationError => 'Merchant verification error.',
            self::RoutingMisconfigured => 'Routing information was misconfigured.',
            self::PaymentTypeNotConfigured => 'The merchant is not configured for this payment type.',
            self::OrderNotFound => 'Order information does not exist.',
            self::SignatureError => 'Signature error (the guide spells this "SGIN ERROR").',
            self::CryptoError => 'Encryption or decryption error — check that the JWE key is AUB\'s encryption key, not its signing key.',
            self::DuplicateReporting => 'Duplicate reporting to the merchant.',
            self::ParameterEmptyOrMalformed => 'A parameter is empty or malformed.',
            self::RequestIdExists => 'That Customer-Request-Id has already been used.',
            self::ServiceDemotion => 'Service degradation; try again shortly.',
            self::RequestTooFrequent => 'Requests are too frequent.',
            self::ReferToIssuer, self::ReferToIssuerAlternate => 'Refer to the card issuer.',
            self::InvalidMerchant => 'Invalid merchant.',
            self::GetCardInformation => 'Please get card information.',
            self::DoNotHonor => 'Do not honor.',
            self::InvalidTransaction => 'Invalid transaction.',
            self::InvalidAmount => 'Invalid amount.',
            self::CheckCardNumber, self::CheckCardNumberDuplicate => 'Please check the card number information.',
            self::LostCard => 'The card is reported lost and cannot be used.',
            self::LockedCard => 'The card is locked and cannot be used.',
            self::InsufficientLimit => 'Insufficient card limit.',
            self::CardExpired => 'The card has expired.',
            self::InvalidPin => 'Invalid PIN.',
            self::NotPermittedToIssuer => 'Transaction not permitted to issuer or cardholder.',
            self::NotPermittedToAcquirer => 'Transaction not permitted to acquirer.',
            self::ExceedsWithdrawalAmount => 'Exceeds the withdrawal amount limit.',
            self::RestrictedCard => 'The card is restricted and cannot be used.',
            self::SecurityViolation => 'Violation of security regulations.',
            self::ExceedsWithdrawalCount => 'Exceeds the withdrawal count limit.',
            self::ModifyPin => 'The PIN must be changed.',
            self::PinTriesExceeded => 'Allowable number of PIN tries exceeded.',
            self::DomesticDebitNotAllowed => 'Domestic debit transactions are not allowed.',
            self::UnableToVerifyPin => 'Unable to verify the PIN; try again later.',
            self::WrongPin => 'Wrong PIN; please re-enter.',
            self::AuthorizationSystemError => 'Authorization system error; contact the administrator.',
            self::SystemErrorTransactionFailed => 'System error; the transaction failed.',
            self::DuplicateTransmission => 'Duplicate transmission detected (the order number already exists).',
            self::TransactionUnknown => 'Transaction result unknown.',
            self::TransactionTimeout => 'Transaction timeout.',
            self::TransactionFailure => 'Transaction failure.',
            self::SystemError => 'System busy.',
        };
    }
}
