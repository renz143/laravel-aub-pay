<?php

namespace Prycegas\AubPay\Requests;

use InvalidArgumentException;
use Prycegas\AubPay\Crypto\JweEncrypter;

/**
 * A direct card payment or pre-authorization (`/online/v1/*`).
 *
 * ⚠ PCI-scoped — see CardDetails.
 *
 * Set `redirectUrl` to run the payment through 3-D Secure: it is where the issuer returns the
 * cardholder after a challenge, and CardClient uses its presence to choose the `threeds/` endpoint.
 * 3-D Secure v2 also expects `billingInformation` and a `customerInformation` carrying the browser
 * fingerprint; without them the issuer is far more likely to challenge, and some decline outright.
 */
class CardPayment
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
        public readonly CardDetails $card,
        public readonly ?CustomerInformation $customer = null,
        public readonly ?BillingInformation $billing = null,
        public readonly ?string $redirectUrl = null,
        public readonly bool $createToken = false,
        public readonly bool $createRecurringToken = false,
        public readonly ?string $description = null,
        public readonly ?string $attach = null,
        public readonly ?int $orderAmount = null,
        public readonly ?int $tipFee = null,
        public readonly ?int $surcharge = null,
        public readonly ?int $vat = null,
    ) {
        if ($orderId === '' || mb_strlen($orderId) > 64) {
            throw new InvalidArgumentException('A card payment needs an orderId of 1-64 characters.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException("A card payment needs a positive amount in minor units; got {$amount}.");
        }
    }

    public function usesThreeDs(): bool
    {
        return filled($this->redirectUrl);
    }

    public function toArray(JweEncrypter $encrypter, array $defaults = []): array
    {
        return array_filter([
            'orderInformation' => array_filter([
                'amount' => $this->amount,
                'orderId' => $this->orderId,
                'goodsDetail' => $this->description,
                'attach' => $this->attach,
                'orderAmount' => $this->orderAmount,
                'tipFee' => $this->tipFee,
                'surcharge' => $this->surcharge,
                'vat' => $this->vat,
            ], static fn ($value) => $value !== null && $value !== ''),
            'card' => $this->card->toArray($encrypter),
            'customerInformation' => $this->customer?->toArray(),
            'billingInformation' => $this->billing?->toArray(),
            'redirectUrl' => $this->redirectUrl ?? ($defaults['redirect_url'] ?? null),
            // Sent only when true: the gateway treats the presence of these flags as intent, and a
            // stray `false` has been read as a request to tokenise on some builds.
            'createToken' => $this->createToken ?: null,
            'createRecurringToken' => $this->createRecurringToken ?: null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
