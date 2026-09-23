<?php

namespace Prycegas\AubPay\Exceptions;

use RuntimeException;

/**
 * The package was asked for something it has not been configured to do — a rail that is disabled,
 * a missing credential, a missing optional dependency. Thrown before any network call, so it can
 * never be confused with a payment that failed.
 */
class ConfigurationException extends RuntimeException
{
    public static function railDisabled(string $rail, string $configKey): self
    {
        return new self(
            "The AUB {$rail} rail is disabled. Set `{$configKey}` to true in config/aub-pay.php "
            . 'once AUB has onboarded you for it.'
        );
    }

    public static function featureDisabled(string $feature, string $configKey): self
    {
        return new self("The AUB {$feature} is disabled. Set `{$configKey}` to true in config/aub-pay.php.");
    }

    public static function missing(string $configKey, string $what): self
    {
        return new self("No {$what} is configured (aub-pay.{$configKey}).");
    }
}
