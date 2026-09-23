<?php

namespace Prycegas\AubPay\Checkout;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hosted checkout page, and the order it is collecting payment for.
 *
 * **The id is the page's address, and its only credential.** The page is public by design — the
 * customer opens it from a link — and it shows their name and email, so the id is 32 hex characters
 * from random_bytes(16): 128 bits, which is what keeps sessions from being walked by guessing.
 *
 * A session is paid by exactly one of its attempts (see CheckoutAttempt) and settled only by the
 * package's own payment events, never by anything the page is told. Its `status` moves
 * active → paid, or active → expired; a payment that lands on an expired session still moves it to
 * paid, because the money did move and hiding that would be worse.
 *
 * @property string $id
 * @property string|null $reference_number
 * @property string $status
 * @property string $currency
 * @property int $amount
 * @property array $line_items
 * @property string|null $description
 * @property array|null $billing
 * @property array $payment_methods
 * @property array|null $metadata
 * @property string $success_url
 * @property string $cancel_url
 * @property string|null $payment_method
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $expires_at
 */
class CheckoutSession extends Model
{
    use StoresDatesInAppTimezone;

    public const ACTIVE = 'active';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    protected $table = 'aub_checkout_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'line_items' => 'array',
        'billing' => 'array',
        'payment_methods' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CheckoutAttempt::class, 'checkout_session_id');
    }

    /**
     * The address to send the customer to — on the checkout host when one is configured.
     */
    public function url(): string
    {
        return Container::getInstance()->make(CheckoutUrls::class)->session($this);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /**
     * Expired on purpose, or past its deadline without being paid. A paid session never is.
     */
    public function isExpired(): bool
    {
        return $this->status === self::EXPIRED
            || ($this->status === self::ACTIVE && $this->expires_at !== null && $this->expires_at->isPast());
    }

    /**
     * Still taking new payments.
     */
    public function isPayable(): bool
    {
        return $this->status === self::ACTIVE && ! $this->isExpired();
    }

    /**
     * What the rails are told the order is — they take one line of free text, not line items.
     */
    public function summary(): string
    {
        return $this->description ?: ($this->reference_number ?: 'Checkout ' . substr($this->id, 0, 8));
    }

    /**
     * `₱ 1,350.00`, spaced as PayMongo prints it. AUB settles in pesos only, so anything else is
     * shown by its code rather than guessed at.
     */
    public function money(int $minor): string
    {
        $symbol = $this->currency === 'PHP' || $this->currency === null ? '₱' : $this->currency;

        return $symbol . ' ' . number_format($minor / 100, 2);
    }
}
