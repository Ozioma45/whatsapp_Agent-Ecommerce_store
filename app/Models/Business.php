<?php

namespace App\Models;

use App\Support\PlanFeatureService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'handle', 'owner_id', 'plan_id', 'current_subscription_id'])]
class Business extends Model
{
    use HasFactory;

    /**
     * Handles that could conflict with the main application or future
     * system routes, and so can never be assigned to a business.
     *
     * @var array<int, string>
     */
    public const RESERVED_HANDLES = [
        'www', 'app', 'admin', 'api', 'dashboard', 'login', 'register', 'support', 'help',
    ];

    /**
     * The user who owns this business.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The store settings belonging to this business.
     */
    public function setting(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }

    /**
     * The categories belonging to this business.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * The products belonging to this business.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The orders placed with this business.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Every business gets its own AI assistant settings row the moment it
     * is created — disabled by default, so AI is never switched on for a
     * business that hasn't chosen it — and its own initial, active
     * subscription mirroring whatever plan it was created with, so every
     * business always has a subscription record from day one (see
     * hasActiveSubscriptionStanding() for why that matters).
     */
    protected static function booted(): void
    {
        static::created(function (Business $business) {
            $business->aiAssistantSettings()->create(AiAssistantSetting::defaults());

            $subscription = $business->subscriptions()->create([
                'plan_id' => $business->plan_id,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now()->toDateString(),
            ]);

            $business->update(['current_subscription_id' => $subscription->id]);
        });
    }

    /**
     * This business's AI assistant configuration (one per business).
     */
    public function aiAssistantSettings(): HasOne
    {
        return $this->hasOne(AiAssistantSetting::class);
    }

    /**
     * This business's WhatsApp Business Platform connection (one per
     * business). Unlike AI settings, this is not auto-created — there is
     * nothing meaningful to default it to until the business connects.
     */
    public function whatsAppIntegrationSetting(): HasOne
    {
        return $this->hasOne(WhatsAppIntegrationSetting::class);
    }

    /**
     * This business's WhatsApp conversations, one per customer number.
     */
    public function whatsAppConversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class);
    }

    /**
     * The business's current SaaS plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * This business's full subscription history (Phase 10A) — every plan
     * change and admin decision, oldest first. Never deleted, only ever
     * superseded (see Subscription).
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * This business's Paystack payment transactions (Phase 10B).
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * The one subscription currently governing this business's
     * entitlements — never simply "the latest row" (a pending request
     * doesn't change this). Null only for data that predates Phase 10A and
     * somehow missed the backfill migration.
     */
    public function currentSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'current_subscription_id');
    }

    /**
     * Whether this business's subscription standing allows it to use its
     * plan's entitlements at all — independent of what the plan itself
     * grants (see PlanFeatureService for that). A business with no
     * subscription record at all is treated as being in good standing:
     * this is a defensive fallback for data that predates Phase 10A, kept
     * so nothing already relying on plan_id alone silently breaks. In
     * practice every business has a subscription from the moment it's
     * created (see booted()) or from the Phase 10A backfill migration, so
     * this fallback should never actually be exercised.
     */
    public function hasActiveSubscriptionStanding(): bool
    {
        return ! $this->currentSubscription || $this->currentSubscription->isInGoodStanding();
    }

    /**
     * Whether this business's plan has the given boolean feature enabled.
     *
     * A thin, ergonomic wrapper — the actual plan/feature entitlement
     * logic lives centrally in PlanFeatureService, never duplicated at
     * call sites. Subscription standing is checked here, once, rather
     * than inside PlanFeatureService, so that service stays exactly what
     * it always was: the single reader of plan_features pivot data.
     */
    public function hasFeature(string $key): bool
    {
        return $this->hasActiveSubscriptionStanding()
            && app(PlanFeatureService::class)->hasFeature($this, $key);
    }

    /**
     * The numeric limit for a limit-type feature on this business's plan,
     * or null when the feature is unlimited, not entitled, or the
     * business's subscription isn't currently in good standing.
     */
    public function featureLimit(string $key): ?int
    {
        if (! $this->hasActiveSubscriptionStanding()) {
            return null;
        }

        return app(PlanFeatureService::class)->getLimit($this, $key);
    }

    /**
     * Whether a given usage count is still within this business's plan
     * limit for the given feature (always true when the limit is null,
     * i.e. unlimited) — and always false when its subscription isn't
     * currently in good standing.
     */
    public function withinFeatureLimit(string $key, int $currentCount): bool
    {
        return $this->hasActiveSubscriptionStanding()
            && app(PlanFeatureService::class)->withinLimit($this, $key, $currentCount);
    }

    /**
     * Use the handle (not the id) when this model is resolved from a route,
     * so public store URLs and route-model binding work off the handle.
     */
    public function getRouteKeyName(): string
    {
        return 'handle';
    }

    /**
     * The public store URL for this business, built from its handle and
     * the platform's configured base domain.
     */
    public function publicUrl(): string
    {
        return route('store.show', ['business' => $this->handle]);
    }

    /**
     * Generate a unique, URL/subdomain-safe handle from a business name,
     * appending "-2", "-3", etc. when the base handle is already taken or
     * reserved for platform use.
     */
    public static function generateUniqueHandle(string $name): string
    {
        $base = Str::slug($name);
        $handle = $base;
        $suffix = 2;

        while (in_array($handle, self::RESERVED_HANDLES, true) || static::where('handle', $handle)->exists()) {
            $handle = "{$base}-{$suffix}";
            $suffix++;
        }

        return $handle;
    }
}
