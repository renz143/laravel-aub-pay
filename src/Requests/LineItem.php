<?php

namespace Prycegas\AubPay\Requests;

use InvalidArgumentException;

/**
 * One line of a checkout session's order summary.
 *
 * `amount` is the **unit** price, in minor units — ₱1,350.00 is `135000` — and the page shows it
 * beside the quantity, the way PayMongo does. The session total is the sum of amount × quantity.
 *
 * Nothing here reaches AUB. The rails take a single amount and a free-text description, so the
 * lines exist only on the checkout page.
 */
class LineItem
{
    public function __construct(
        public readonly string $name,
        public readonly int $amount,
        public readonly int $quantity = 1,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A checkout line item needs a name.');
        }

        if ($amount < 0) {
            throw new InvalidArgumentException("A line item's amount is in minor units and cannot be negative; got {$amount}.");
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException("A line item needs a quantity of at least 1; got {$quantity}.");
        }

        if ($imageUrl !== null) {
            CheckoutSessionRequest::assertWebUrl('imageUrl', $imageUrl);
        }
    }

    public function total(): int
    {
        return $this->amount * $this->quantity;
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'amount' => $this->amount,
            'quantity' => $this->quantity,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
