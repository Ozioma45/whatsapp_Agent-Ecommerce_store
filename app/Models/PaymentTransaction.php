<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One Paystack transaction attempt for one business's plan purchase.
 * Amount is captured in kobo (the smallest NGN unit) at the moment of
 * initialization, from the plan's price at that time — never from
 * anything the browser submits, and never rewritten by a later plan price
 * change (see App\Support\Payments\PaymentService).
 */
#[Fillable(['business_id', 'plan_id', 'subscription_id', 'reference', 'amount', 'currency', 'status', 'paystack_transaction_id', 'channel', 'initialized_at', 'verified_at', 'failure_reason'])]
class PaymentTransaction extends Model
{
    use HasFactory;

    /**
     * Created locally; Paystack hasn't yet confirmed an outcome.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Independently verified with Paystack — see PaymentService::verifyAndActivate().
     */
    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCESSFUL,
        self::STATUS_FAILED,
        self::STATUS_ABANDONED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'initialized_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The subscription (request) this payment is for.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The amount, formatted with the Naira sign — matches Plan::formattedPrice().
     */
    public function formattedAmount(): string
    {
        return '₦'.number_format($this->amount / 100, 2);
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }

    /**
     * Generate a unique reference to hand to Paystack. Never a raw
     * database id — this is what both the callback and the webhook use to
     * find this exact transaction, so it must be unguessable and unique.
     */
    public static function generateReference(): string
    {
        do {
            $reference = 'PSK-'.now()->format('Ymd').'-'.Str::upper(Str::random(12));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }
}
