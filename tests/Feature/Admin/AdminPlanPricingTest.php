<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin plan pricing management: viewing and editing a plan's price only —
 * never its features, limits, or any business's existing subscription.
 */
class AdminPlanPricingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    /**
     * A full plan-update payload matching what the real form submits,
     * with the given price override.
     */
    private function payload(Plan $plan, string $price): array
    {
        $features = [];

        foreach ($plan->features as $feature) {
            $features[$feature->id] = [
                'enabled' => $feature->pivot->enabled ? '1' : '0',
                'limit' => $feature->pivot->limit,
            ];
        }

        return [
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $price,
            'is_active' => $plan->is_active ? '1' : '0',
            'features' => $features,
        ];
    }

    public function test_admin_can_view_each_plans_price(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/plans')
            ->assertOk()
            ->assertSee('₦0.00')
            ->assertSee('₦15,000.00')
            ->assertSee('₦45,000.00');

        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $this->actingAs($admin)->get("/admin/plans/{$standard->id}")->assertOk()->assertSee('0.00');
    }

    public function test_admin_can_update_a_plans_price(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();

        $response = $this->actingAs($admin)->patch("/admin/plans/{$standard->id}", $this->payload($standard, '2500.00'));

        $response->assertRedirect(route('admin.plans.show', $standard));
        $this->assertSame('2500.00', $standard->fresh()->price);
    }

    public function test_each_plan_can_be_priced_independently(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $pro = Plan::with('features')->where('slug', Plan::PRO)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $originalPremiumPrice = $premium->price;

        $this->actingAs($admin)->patch("/admin/plans/{$pro->id}", $this->payload($pro, '20000.00'));

        $this->assertSame('20000.00', $pro->fresh()->price);
        $this->assertSame($originalPremiumPrice, $premium->fresh()->price);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $originalPrice = $plan->price;

        $response = $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $this->payload($plan, '-10'));

        $response->assertSessionHasErrors('price');
        $this->assertSame($originalPrice, $plan->fresh()->price);
    }

    public function test_a_non_numeric_price_is_rejected(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();

        $response = $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $this->payload($plan, 'not-a-price'));

        $response->assertSessionHasErrors('price');
    }

    public function test_a_missing_price_is_rejected(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $payload = $this->payload($plan, '10');
        unset($payload['price']);

        $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $payload)
            ->assertSessionHasErrors('price');
    }

    public function test_a_price_with_too_much_precision_is_rejected(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();

        $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $this->payload($plan, '10.999'))
            ->assertSessionHasErrors('price');
    }

    public function test_zero_is_a_valid_price(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::PRO)->firstOrFail();

        $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $this->payload($plan, '0'))
            ->assertRedirect(route('admin.plans.show', $plan));

        $this->assertSame('0.00', $plan->fresh()->price);
    }

    public function test_a_business_owner_cannot_view_the_plan_pricing_page(): void
    {
        $this->seed(PlanSeeder::class);
        $owner = User::factory()->create();
        Business::factory()->create(['owner_id' => $owner->id]);
        $plan = Plan::where('slug', Plan::STANDARD)->firstOrFail();

        $this->actingAs($owner)->get("/admin/plans/{$plan->id}")->assertForbidden();
    }

    public function test_a_business_owner_cannot_change_a_plans_price_via_a_manipulated_request(): void
    {
        $this->seed(PlanSeeder::class);
        $owner = User::factory()->create();
        Business::factory()->create(['owner_id' => $owner->id]);
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $originalPrice = $plan->price;

        $response = $this->actingAs($owner)->patch("/admin/plans/{$plan->id}", $this->payload($plan, '1.00'));

        $response->assertForbidden();
        $this->assertSame($originalPrice, $plan->fresh()->price);
    }

    public function test_a_guest_cannot_change_a_plans_price(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', Plan::STANDARD)->firstOrFail();

        $this->patch("/admin/plans/{$plan->id}", ['price' => '1.00'])->assertRedirect('/login');
    }

    public function test_updating_a_price_does_not_change_features_limits_or_existing_subscriptions(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $subscriptionId = $business->current_subscription_id;

        $productsLimitBefore = $business->featureLimit(Feature::PRODUCTS_LIMIT);
        $brandingBefore = $business->hasFeature(Feature::CUSTOM_BRANDING);

        $this->actingAs($admin)->patch("/admin/plans/{$standard->id}", $this->payload($standard, '999.99'));

        $business->refresh();
        $this->assertSame($productsLimitBefore, $business->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertSame($brandingBefore, $business->hasFeature(Feature::CUSTOM_BRANDING));
        $this->assertSame($subscriptionId, $business->current_subscription_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->status);
        $this->assertSame(1, $business->subscriptions()->count());
    }

    public function test_the_business_subscription_page_reflects_an_updated_price(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->actingAs($admin)->patch("/admin/plans/{$standard->id}", $this->payload($standard, '3333.33'));

        $this->actingAs($business->owner)->get('/subscription')
            ->assertOk()
            ->assertSee('₦3,333.33');
    }

    public function test_re_running_the_seeder_does_not_overwrite_an_admin_configured_price(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();

        $this->actingAs($admin)->patch("/admin/plans/{$standard->id}", $this->payload($standard, '7777.00'));
        $this->assertSame('7777.00', $standard->fresh()->price);

        $this->seed(PlanSeeder::class);

        $this->assertSame('7777.00', $standard->fresh()->price);
    }

    public function test_the_seeder_still_prices_a_brand_new_plan_it_creates(): void
    {
        // Simulates a completely fresh database: the seeder must still
        // seed its own placeholder prices for plans it creates itself.
        $this->seed(PlanSeeder::class);

        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $this->assertSame('0.00', $standard->price);
        $this->assertSame('15000.00', $pro->price);
        $this->assertSame('45000.00', $premium->price);
    }
}
