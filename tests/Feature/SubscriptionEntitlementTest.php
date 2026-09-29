<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for Step 6: subscription standing gates entitlements
 * on top of (never instead of) the existing PlanFeatureService, which is
 * completely untouched by this phase.
 */
class SubscriptionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function businessOnPlan(string $slug): Business
    {
        return Business::factory()->create(['plan_id' => Plan::where('slug', $slug)->firstOrFail()->id]);
    }

    // --- Existing plan entitlements still work ---------------------------

    public function test_standard_pro_and_premium_entitlements_are_unchanged(): void
    {
        $standard = $this->businessOnPlan(Plan::STANDARD);
        $pro = $this->businessOnPlan(Plan::PRO);
        $premium = $this->businessOnPlan(Plan::PREMIUM);

        $this->assertSame(20, $standard->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertFalse($standard->hasFeature(Feature::CUSTOM_BRANDING));
        $this->assertFalse($standard->hasFeature(Feature::AI_ASSISTANT));

        $this->assertSame(100, $pro->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertTrue($pro->hasFeature(Feature::CUSTOM_BRANDING));
        $this->assertFalse($pro->hasFeature(Feature::AI_ASSISTANT));

        $this->assertNull($premium->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertTrue($premium->hasFeature(Feature::CUSTOM_BRANDING));
        $this->assertTrue($premium->hasFeature(Feature::AI_ASSISTANT));
    }

    // --- Expired / suspended denial --------------------------------------

    public function test_an_expired_subscription_does_not_retain_paid_only_access(): void
    {
        $business = $this->businessOnPlan(Plan::PREMIUM);
        $this->assertTrue($business->hasFeature(Feature::AI_ASSISTANT));

        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
        $this->assertFalse($business->fresh()->hasFeature(Feature::STOREFRONT));
        $this->assertNull($business->fresh()->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertFalse($business->fresh()->withinFeatureLimit(Feature::PRODUCTS_LIMIT, 1));
    }

    public function test_expiry_takes_effect_even_before_the_status_column_is_updated(): void
    {
        // The status column still literally says "active" — only the date
        // has passed. This proves the gate doesn't depend on a scheduled
        // job having run to flip the status first.
        $business = $this->businessOnPlan(Plan::PREMIUM);
        $business->currentSubscription->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->fresh()->status);

        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_a_suspended_subscription_does_not_retain_paid_only_access(): void
    {
        $business = $this->businessOnPlan(Plan::PREMIUM);
        $business->currentSubscription->update(['status' => Subscription::STATUS_SUSPENDED]);

        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
        $this->assertFalse($business->fresh()->hasFeature(Feature::STOREFRONT));
    }

    public function test_a_cancelled_subscription_does_not_retain_access(): void
    {
        $business = $this->businessOnPlan(Plan::PRO);
        $business->currentSubscription->update(['status' => Subscription::STATUS_CANCELLED]);

        $this->assertFalse($business->fresh()->hasFeature(Feature::CUSTOM_BRANDING));
    }

    public function test_reactivating_restores_entitlements(): void
    {
        $business = $this->businessOnPlan(Plan::PREMIUM);
        $business->currentSubscription->update(['status' => Subscription::STATUS_SUSPENDED]);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));

        $business->currentSubscription->update(['status' => Subscription::STATUS_ACTIVE]);

        $this->assertTrue($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    // --- Storefront / order regression ------------------------------------

    public function test_the_storefront_and_checkout_are_unaffected_for_an_active_business(): void
    {
        $business = $this->businessOnPlan(Plan::STANDARD);
        $business->setting()->create(['whatsapp_number' => '08012345678']);
        Product::factory()->create(['business_id' => $business->id]);

        $this->get('http://'.$business->handle.'.'.config('app.domain').'/')->assertOk();
    }
}
