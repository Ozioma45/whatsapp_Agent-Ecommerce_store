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

#[Fillable(['name', 'handle', 'owner_id', 'plan_id'])]
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
     * The business's current SaaS plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether this business's plan has the given boolean feature enabled.
     *
     * A thin, ergonomic wrapper — the actual entitlement logic lives
     * centrally in PlanFeatureService, never duplicated at call sites.
     */
    public function hasFeature(string $key): bool
    {
        return app(PlanFeatureService::class)->hasFeature($this, $key);
    }

    /**
     * The numeric limit for a limit-type feature on this business's plan,
     * or null when the feature is unlimited (or not entitled at all).
     */
    public function featureLimit(string $key): ?int
    {
        return app(PlanFeatureService::class)->getLimit($this, $key);
    }

    /**
     * Whether a given usage count is still within this business's plan
     * limit for the given feature (always true when the limit is null,
     * i.e. unlimited).
     */
    public function withinFeatureLimit(string $key, int $currentCount): bool
    {
        return app(PlanFeatureService::class)->withinLimit($this, $key, $currentCount);
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
