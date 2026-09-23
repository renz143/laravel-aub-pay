<?php

namespace Prycegas\AubPay\Checkout;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One payment started from a checkout session — a QR code shown, or a cashier page opened.
 *
 * A session gathers several when the customer switches method or lets a QR code lapse, and each is a
 * separate order at AUB under its own `order_id`: the wallet spec is explicit that paying again
 * needs a new order number (§10.1), and the cashier rail keys everything on the id it was given.
 *
 * Attempts are the unit AUB's notifications resolve to. Their `status` is pending until a payment
 * event settles them; failed when the gateway says so or the attempt could not be opened; paid only
 * on a confirmed payment for the right amount. A failed attempt can still turn out paid — if AUB says
 * so later, AUB wins — but a paid one never goes back.
 *
 * @property int $id
 * @property string $checkout_session_id
 * @property string $method
 * @property string $rail
 * @property string $order_id
 * @property int $amount
 * @property string $status
 * @property string|null $gateway_reference
 * @property string|null $invoice_id
 * @property string|null $redirect_url
 * @property string|null $qr_code
 * @property string|null $qr_image_url
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $last_checked_at
 * @property CarbonInterface|null $paid_at
 */
class CheckoutAttempt extends Model
{
    use StoresDatesInAppTimezone;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    /**
     * Every checkout order id starts with this, which lets the settlement listener pass over the
     * application's own orders without a query.
     */
    public const ORDER_PREFIX = 'CS_';

    protected $table = 'aub_checkout_attempts';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * The id AUB will know this attempt by.
     *
     * Built to the wallet rail's rules — 5 to 32 characters, letters, digits and underscores — which
     * also satisfy the cashier rail's 64, so one scheme serves both. The session's prefix is there
     * for whoever has to find the order in AUB's portal: `CS_5f1c0b7e9a2d_K3J9Q2ZX`.
     */
    public static function newOrderId(CheckoutSession $session): string
    {
        return self::ORDER_PREFIX . substr($session->id, 0, 12) . '_' . Str::upper(Str::random(8));
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    /**
     * Unsettled and still inside its own window, so the customer can still pay it.
     */
    public function isLive(): bool
    {
        return $this->isPending() && $this->secondsRemaining() > 0;
    }

    /**
     * Whole seconds until the attempt lapses. Timestamps rather than Carbon's diff helpers, whose
     * sign and return type changed between Carbon 2 and 3.
     */
    public function secondsRemaining(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, $this->expires_at->getTimestamp() - now()->getTimestamp());
    }
}
