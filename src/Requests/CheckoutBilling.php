<?php

namespace Prycegas\AubPay\Requests;

/**
 * Who a checkout session is billed to, shown on the page as "Billed to {name}, {email}".
 *
 * Display only. Neither AUB rail has a field for these, so they are never sent to the gateway.
 */
class CheckoutBilling
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
