<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPlanChangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_admin_can_change_a_business_from_standard_to_pro(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $response = $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => $pro->id]);

        $response->assertRedirect(route('admin.businesses.show', $business));
        $this->assertSame($pro->id, $business->fresh()->plan_id);
    }

    public function test_admin_can_change_pro_to_premium(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);

        $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => $premium->id]);

        $this->assertSame($premium->id, $business->fresh()->plan_id);
    }

    public function test_the_business_immediately_uses_the_new_entitlements(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->assertSame(20, $business->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertFalse($business->hasFeature(Feature::CUSTOM_BRANDING));

        $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => $pro->id]);
        $business->refresh();

        $this->assertSame(100, $business->featureLimit(Feature::PRODUCTS_LIMIT));
        $this->assertTrue($business->hasFeature(Feature::CUSTOM_BRANDING));
    }

    public function test_an_invalid_plan_is_rejected(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create();

        $response = $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => 999999]);

        $response->assertSessionHasErrors('plan_id');
    }

    public function test_an_inactive_plan_is_rejected(): void
    {
        $admin = $this->admin();
        $inactivePlan = Plan::factory()->create(['is_active' => false]);
        $business = Business::factory()->create();

        $response = $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => $inactivePlan->id]);

        $response->assertSessionHasErrors('plan_id');
    }

    public function test_a_business_owner_cannot_change_their_own_plan(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $otherPlan = Plan::factory()->create(['is_active' => true]);

        $response = $this->actingAs($owner)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => $otherPlan->id]);

        $response->assertForbidden();
    }
}
