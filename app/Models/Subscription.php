<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One episode of a business's subscription to a plan. A business
 * accumulates one row per plan change or admin decision — an old row is
 * only ever marked "cancelled" when superseded, never deleted, so the
 * full subscription history survives (see Business::subscriptions()).
 *
 * The single row currently governing a business's entitlements is the one
 * businesses.current_subscription_id points to
 * (Business::currentSubscription()) — never simply "the latest row": a
 * pending request sits alongside the still-current row until an admin
 * decides it, and is never itself the current pointer.
 */
#[Fillable(['business_id', 'plan_id', 'status', 'billing_period', 'starts_at', 'expires_at', 'requested_at', 'decided_at', 'decided_by'])]
class Subscription extends Model
{
    use HasFactory;

    /**
     * An owner-submitted plan-change request awaiting admin review. Never
     * the value of current_subscription_id.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Currently in effect — the business receives its plan's entitlements
     * (see Business::hasActiveSubscriptionStanding()), unless expires_at
     * has since passed (see isEffectivelyExpired()).
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Explicitly marked as having run out. A still-"active" row whose
     * expires_at has quietly passed is *also* denied entitlements (see
     * isInGoodStanding()) even before anyone sets this — this status
     * exists for clear reporting, not to be the only enforcement point.
     */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Manually suspended by an admin. Denies entitlements until
     * reactivated.
     */
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * No longer in effect — either superseded by a newer subscription, or
     * a pending request an admin rejected. The workflow has no separate
     * "rejected" status; decided_by/decided_at being set is what
     * distinguishes a deliberate admin decision from a row that was simply
     * superseded by a plan change.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_SUSPENDED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'expires_at' => 'date',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The business this subscription belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The plan this subscription is (or was) for.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The admin who approved, rejected, assigned, suspended, or
     * reactivated this subscription, if any.
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Whether expires_at has passed, regardless of what the status column
     * currently says — this is what makes an un-actioned expiry actually
     * take effect without needing a scheduled job.
     */
    public function isEffectivelyExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this subscription currently grants its plan's entitlements.
     */
    public function isInGoodStanding(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isEffectivelyExpired();
    }

    /**
     * A human-readable status label — reports a quietly-past-expiry
     * "active" row as "Expired" rather than leaving the stale label.
     */
    public function statusLabel(): string
    {
        if ($this->status === self::STATUS_ACTIVE && $this->isEffectivelyExpired()) {
            return 'Expired';
        }

        return ucfirst($this->status);
    }
}
