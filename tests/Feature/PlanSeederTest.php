<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_three_initial_plans(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertDatabaseHas('plans', ['slug' => Plan::STANDARD]);
        $this->assertDatabaseHas('plans', ['slug' => Plan::PRO]);
        $this->assertDatabaseHas('plans', ['slug' => Plan::PREMIUM]);
        $this->assertSame(3, Plan::count());
    }

    public function test_it_creates_the_seven_initial_features(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertSame(7, Feature::count());

        foreach ([
            Feature::STOREFRONT, Feature::WHATSAPP_ORDERING, Feature::ORDER_MANAGEMENT,
            Feature::PRODUCTS_LIMIT, Feature::CATEGORIES, Feature::CUSTOM_BRANDING, Feature::AI_ASSISTANT,
        ] as $key) {
            $this->assertDatabaseHas('features', ['key' => $key]);
        }
    }

    public function test_standard_plan_entitlements_match_the_spec(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', Plan::STANDARD)->firstOrFail();

        foreach ([Feature::STOREFRONT, Feature::WHATSAPP_ORDERING, Feature::ORDER_MANAGEMENT, Feature::CATEGORIES] as $key) {
            $this->assertTrue((bool) $plan->features()->where('key', $key)->first()->pivot->enabled, $key);
        }

        $this->assertSame(20, $plan->features()->where('key', Feature::PRODUCTS_LIMIT)->first()->pivot->limit);
        $this->assertFalse((bool) $plan->features()->where('key', Feature::CUSTOM_BRANDING)->first()->pivot->enabled);
        $this->assertFalse((bool) $plan->features()->where('key', Feature::AI_ASSISTANT)->first()->pivot->enabled);
    }

    public function test_pro_plan_entitlements_match_the_spec(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', Plan::PRO)->firstOrFail();

        $this->assertTrue((bool) $plan->features()->where('key', Feature::CUSTOM_BRANDING)->first()->pivot->enabled);
        $this->assertSame(100, $plan->features()->where('key', Feature::PRODUCTS_LIMIT)->first()->pivot->limit);
        $this->assertFalse((bool) $plan->features()->where('key', Feature::AI_ASSISTANT)->first()->pivot->enabled);
    }

    public function test_premium_plan_entitlements_match_the_spec(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $this->assertTrue((bool) $plan->features()->where('key', Feature::CUSTOM_BRANDING)->first()->pivot->enabled);
        $this->assertTrue((bool) $plan->features()->where('key', Feature::AI_ASSISTANT)->first()->pivot->enabled);
        $this->assertNull($plan->features()->where('key', Feature::PRODUCTS_LIMIT)->first()->pivot->limit);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->assertSame(3, Plan::count());
        $this->assertSame(7, Feature::count());
        $this->assertSame(21, DB::table('plan_features')->count());
    }

    public function test_it_backfills_an_existing_business_with_no_plan(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => null]);

        $this->seed(PlanSeeder::class);

        $this->assertSame(Plan::STANDARD, $business->fresh()->plan->slug);
    }

    public function test_it_does_not_change_a_businesss_existing_plan(): void
    {
        $this->seed(PlanSeeder::class);
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => $premium->id]);

        $this->seed(PlanSeeder::class);

        $this->assertSame(Plan::PREMIUM, $business->fresh()->plan->slug);
    }

    public function test_it_does_not_delete_the_businesss_other_data(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => null, 'name' => 'Keep Me']);

        $this->seed(PlanSeeder::class);

        $this->assertDatabaseHas('businesses', ['id' => $business->id, 'name' => 'Keep Me']);
    }
}
