<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFeatureServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: Plan}
     */
    private function businessWithPlan(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => $plan->id]);

        return [$business, $plan];
    }

    public function test_has_feature_is_true_when_enabled(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'test_feature']);
        $plan->features()->attach($feature->id, ['enabled' => true]);

        $this->assertTrue($business->hasFeature('test_feature'));
    }

    public function test_has_feature_is_false_when_disabled(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'test_feature']);
        $plan->features()->attach($feature->id, ['enabled' => false]);

        $this->assertFalse($business->hasFeature('test_feature'));
    }

    public function test_has_feature_is_false_when_the_plan_has_no_configuration_for_it(): void
    {
        [$business] = $this->businessWithPlan();

        $this->assertFalse($business->hasFeature('nonexistent_feature'));
    }

    public function test_has_feature_is_false_when_the_business_has_no_plan(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => null]);

        $this->assertFalse($business->hasFeature('anything'));
    }

    public function test_get_limit_returns_the_configured_limit(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'products_limit', 'type' => Feature::TYPE_LIMIT]);
        $plan->features()->attach($feature->id, ['enabled' => true, 'limit' => 20]);

        $this->assertSame(20, $business->featureLimit('products_limit'));
    }

    public function test_get_limit_returns_null_for_unlimited(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'products_limit', 'type' => Feature::TYPE_LIMIT]);
        $plan->features()->attach($feature->id, ['enabled' => true, 'limit' => null]);

        $this->assertNull($business->featureLimit('products_limit'));
    }

    public function test_get_limit_returns_null_when_the_feature_is_disabled(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'products_limit', 'type' => Feature::TYPE_LIMIT]);
        $plan->features()->attach($feature->id, ['enabled' => false, 'limit' => 20]);

        $this->assertNull($business->featureLimit('products_limit'));
    }

    public function test_within_limit_is_true_below_the_cap(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'products_limit', 'type' => Feature::TYPE_LIMIT]);
        $plan->features()->attach($feature->id, ['enabled' => true, 'limit' => 20]);

        $this->assertTrue($business->withinFeatureLimit('products_limit', 19));
    }

    public function test_within_limit_is_false_at_the_cap(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'products_limit', 'type' => Feature::TYPE_LIMIT]);
        $plan->features()->attach($feature->id, ['enabled' => true, 'limit' => 20]);

        $this->assertFalse($business->withinFeatureLimit('products_limit', 20));
    }

    public function test_within_limit_is_always_true_when_unlimited(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'products_limit', 'type' => Feature::TYPE_LIMIT]);
        $plan->features()->attach($feature->id, ['enabled' => true, 'limit' => null]);

        $this->assertTrue($business->withinFeatureLimit('products_limit', 999999));
    }

    public function test_within_limit_is_false_when_the_feature_is_disabled(): void
    {
        [$business, $plan] = $this->businessWithPlan();
        $feature = Feature::factory()->create(['key' => 'products_limit', 'type' => Feature::TYPE_LIMIT]);
        $plan->features()->attach($feature->id, ['enabled' => false, 'limit' => 20]);

        $this->assertFalse($business->withinFeatureLimit('products_limit', 1));
    }

    // --- Against the real seeded plan configuration ------------------

    public function test_the_standard_plan_matches_its_spec_via_the_service(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => $plan->id]);

        $this->assertTrue($business->hasFeature(Feature::WHATSAPP_ORDERING));
        $this->assertTrue($business->hasFeature(Feature::CATEGORIES));
        $this->assertFalse($business->hasFeature(Feature::CUSTOM_BRANDING));
        $this->assertFalse($business->hasFeature(Feature::AI_ASSISTANT));
        $this->assertSame(20, $business->featureLimit(Feature::PRODUCTS_LIMIT));
    }

    public function test_the_premium_plan_is_unlimited_via_the_service(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => $plan->id]);

        $this->assertTrue($business->hasFeature(Feature::AI_ASSISTANT));
        $this->assertNull($business->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertTrue($business->withinFeatureLimit(Feature::PRODUCTS_LIMIT, 100000));
    }
}
