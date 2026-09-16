<?php

namespace Prycegas\AubPay\Requests;

/**
 * Cardholder billing address. Required for 3-D Secure v2, where the issuer uses it as a risk
 * signal — a missing or obviously wrong address makes a challenge more likely, not less.
 *
 * `country` is a two-letter ISO 3166-1 alpha-2 code ("PH").
 */
class BillingInformation
{
    public function __construct(
        public readonly ?string $street1 = null,
        public readonly ?string $street2 = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $postcode = null,
        public readonly ?string $country = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'street1' => $this->street1,
            'street2' => $this->street2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
