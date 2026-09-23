<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(): User
    {
        $owner = User::factory()->create(['role' => User::ROLE_BUSINESS_OWNER]);
        Business::factory()->create(['owner_id' => $owner->id]);

        return $owner;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_a_business_owner_cannot_access_the_admin_dashboard(): void
    {
        $this->actingAs($this->businessOwner())->get('/admin')->assertForbidden();
    }

    public function test_a_business_owner_cannot_access_the_admin_businesses_page(): void
    {
        $this->actingAs($this->businessOwner())->get('/admin/businesses')->assertForbidden();
    }

    public function test_a_business_owner_cannot_view_a_business_detail_page(): void
    {
        $owner = $this->businessOwner();
        $other = Business::factory()->create();

        $this->actingAs($owner)->get("/admin/businesses/{$other->handle}")->assertForbidden();
    }

    public function test_a_business_owner_cannot_change_another_businesss_plan(): void
    {
        $owner = $this->businessOwner();
        $other = Business::factory()->create();
        $plan = Plan::factory()->create(['is_active' => true]);

        $this->actingAs($owner)
            ->patch("/admin/businesses/{$other->handle}/plan", ['plan_id' => $plan->id])
            ->assertForbidden();
    }

    public function test_a_business_owner_cannot_modify_plans(): void
    {
        $owner = $this->businessOwner();
        $plan = Plan::factory()->create();

        $this->actingAs($owner)->patch("/admin/plans/{$plan->id}", ['name' => 'Hacked'])->assertForbidden();
    }

    public function test_a_business_owner_cannot_modify_features(): void
    {
        $owner = $this->businessOwner();

        $this->actingAs($owner)->get('/admin/features')->assertForbidden();
    }

    public function test_a_business_owner_cannot_modify_settings(): void
    {
        $owner = $this->businessOwner();

        $this->actingAs($owner)->patch('/admin/settings', ['platform_name' => 'Hacked'])->assertForbidden();
    }

    public function test_a_business_owner_cannot_promote_themselves_to_admin(): void
    {
        $owner = $this->businessOwner();

        $this->actingAs($owner)
            ->patch("/admin/users/{$owner->id}/role", ['role' => User::ROLE_ADMIN])
            ->assertForbidden();

        $this->assertFalse($owner->fresh()->isAdmin());
    }

    public function test_an_unauthenticated_user_cannot_access_admin_pages(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_an_admin_can_access_the_admin_dashboard(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }
}
