<?php

namespace Prycegas\AubPay\Requests;

use InvalidArgumentException;

/**
 * A hosted checkout session: an order summary, and the ways the customer may pay for it.
 *
 * Shaped after PayMongo's checkout session so an integration moving across keeps its vocabulary —
 * line items, success and cancel URLs, a reference number, billing, metadata. What differs is
 * underneath: each payment the customer starts becomes its own AUB order, on whichever rail the
 * chosen method uses.
 *
 * `paymentMethods` are keys from `aub-pay.checkout.methods` — `qrph` and `card` out of the box —
 * and the page offers them in the order given.
 *
 * The URLs are checked here because the page puts them in `href` and `src` attributes, where a
 * `javascript:` URL would run in the customer's browser when they tap the back arrow.
 */
class CheckoutSessionRequest
{
    /**
     * @param  LineItem[]  $lineItems
     * @param  string[]  $paymentMethods
     * @param  int|null  $expiresAfter  minutes; defaults to `aub-pay.checkout.expires_after`
     */
    public function __construct(
        public readonly array $lineItems,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly array $paymentMethods = ['qrph', 'card'],
        public readonly ?string $referenceNumber = null,
        public readonly ?string $description = null,
        public readonly ?CheckoutBilling $billing = null,
        public readonly array $metadata = [],
        public readonly ?int $expiresAfter = null,
    ) {
        if ($lineItems === []) {
            throw new InvalidArgumentException('A checkout session needs at least one line item.');
        }

        foreach ($lineItems as $item) {
            if (! $item instanceof LineItem) {
                throw new InvalidArgumentException('Checkout line items must be ' . LineItem::class . ' instances.');
            }
        }

        if ($this->amount() <= 0) {
            throw new InvalidArgumentException('A checkout session needs a positive total in minor units.');
        }

        self::assertWebUrl('successUrl', $successUrl);
        self::assertWebUrl('cancelUrl', $cancelUrl);

        if ($paymentMethods === []) {
            throw new InvalidArgumentException('A checkout session needs at least one payment method.');
        }

        if ($referenceNumber !== null && mb_strlen($referenceNumber) > 64) {
            throw new InvalidArgumentException('A checkout reference number is limited to 64 characters.');
        }

        if ($expiresAfter !== null && $expiresAfter < 1) {
            throw new InvalidArgumentException("A checkout session must stay open for at least a minute; got {$expiresAfter}.");
        }
    }

    /**
     * The session total, in minor units.
     */
    public function amount(): int
    {
        return array_sum(array_map(static fn (LineItem $item) => $item->total(), $this->lineItems));
    }

    /**
     * @return string[]
     */
    public function methods(): array
    {
        return array_values(array_unique($this->paymentMethods));
    }

    public static function assertWebUrl(string $field, string $url): void
    {
        if (! preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("`{$field}` must be an absolute http(s) URL; got `{$url}`.");
        }
    }
}
