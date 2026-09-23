<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Feature;

/**
 * The single source of truth for what a business's SaaS plan entitles it
 * to. Nothing else in the application should query plans/features/pivot
 * data directly — call this service (or the Business::hasFeature() /
 * featureLimit() / withinFeatureLimit() convenience wrappers around it)
 * instead, so entitlement logic is never duplicated or hardcoded at
 * individual call sites.
 *
 * This phase only builds the entitlement architecture itself — no
 * controller or view in the application enforces these yet.
 */
class PlanFeatureService
{
    /**
     * Whether the business's plan has the given feature enabled.
     *
     * For a limit-type feature, "enabled" means the business has any
     * access to it at all (see getLimit() for the actual numeric cap).
     */
    public function hasFeature(Business $business, string $key): bool
    {
        return (bool) $this->planFeature($business, $key)?->pivot->enabled;
    }

    /**
     * The numeric limit configured for a limit-type feature, or null when
     * the feature is unlimited, disabled, or the business has no plan.
     */
    public function getLimit(Business $business, string $key): ?int
    {
        $planFeature = $this->planFeature($business, $key);

        if (! $planFeature || ! $planFeature->pivot->enabled) {
            return null;
        }

        return $planFeature->pivot->limit;
    }

    /**
     * Whether a usage count is still within the business's plan limit for
     * the given feature. A null limit means unlimited, so this is always
     * true in that case (as long as the feature is actually enabled).
     */
    public function withinLimit(Business $business, string $key, int $currentCount): bool
    {
        $planFeature = $this->planFeature($business, $key);

        if (! $planFeature || ! $planFeature->pivot->enabled) {
            return false;
        }

        $limit = $planFeature->pivot->limit;

        return $limit === null || $currentCount < $limit;
    }

    /**
     * The feature row for this business's plan, with the plan-specific
     * enabled/limit pivot data loaded — or null if the business has no
     * plan, or its plan has no configuration for this feature at all.
     */
    private function planFeature(Business $business, string $key): ?Feature
    {
        return $business->plan?->features()->where('key', $key)->first();
    }
}
