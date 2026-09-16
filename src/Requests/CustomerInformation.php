<?php

namespace Prycegas\AubPay\Requests;

/**
 * The cardholder. `firstName`/`lastName` are enough for a plain payment; 3-D Secure v2 wants the
 * phone number and the browser fingerprint as well.
 */
class CustomerInformation
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?BrowserInformation $browser = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'phone' => $this->phone,
            'email' => $this->email,
            'browserInformation' => $this->browser?->toArray(),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
