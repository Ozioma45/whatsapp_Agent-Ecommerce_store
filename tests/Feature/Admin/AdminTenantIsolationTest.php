<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_admin_can_see_all_businesses(): void
    {
        $admin = $this->admin();
        Business::factory()->create(['name' => 'Business A']);
        Business::factory()->create(['name' => 'Business B']);

        $response = $this->actingAs($admin)->get('/admin/businesses');

        $response->assertSee('Business A')->assertSee('Business B');
    }

    public function test_a_normal_business_owner_remains_restricted_to_their_own_business(): void
    {
        $ownerA = User::factory()->create();
        Business::factory()->create(['owner_id' => $ownerA->id, 'name' => 'Business A']);
        Business::factory()->create(['name' => 'Business B']);

        $response = $this->actingAs($ownerA)->get('/dashboard');

        $response->assertOk()->assertSee('Business A')->assertDontSee('Business B');
    }

    public function test_changing_business_as_plan_does_not_affect_business_b(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $businessA = Business::factory()->create(['plan_id' => $standard->id]);
        $businessB = Business::factory()->create(['plan_id' => $standard->id]);

        $this->actingAs($admin)->patch("/admin/businesses/{$businessA->handle}/plan", ['plan_id' => $pro->id]);

        $this->assertSame($pro->id, $businessA->fresh()->plan_id);
        $this->assertSame($standard->id, $businessB->fresh()->plan_id);
    }
}
