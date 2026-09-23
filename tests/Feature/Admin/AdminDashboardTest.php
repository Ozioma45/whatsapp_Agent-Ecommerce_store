<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_the_dashboard_loads(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }

    public function test_it_shows_the_correct_business_count(): void
    {
        $admin = $this->admin();
        Business::factory()->count(3)->create();

        $this->actingAs($admin)->get('/admin')->assertSee((string) Business::count());
    }

    public function test_it_shows_the_correct_user_count(): void
    {
        $admin = $this->admin();
        User::factory()->count(2)->create();

        $this->actingAs($admin)->get('/admin')->assertSee((string) User::count());
    }

    public function test_it_shows_the_correct_order_count(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create();

        // Created one at a time, matching how orders are actually created in
        // the real app (via sequential checkout requests) — Order::generateOrderNumber()
        // checks uniqueness against already-committed rows, so batch-creating
        // several at once via count() would race and collide.
        for ($i = 0; $i < 5; $i++) {
            Order::factory()->for($business)->create();
        }

        $this->actingAs($admin)->get('/admin')->assertSee((string) Order::count());
    }

    public function test_it_shows_correct_plan_counts(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        Business::factory()->count(2)->create(['plan_id' => $standard->id]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertSee('Standard');
        $response->assertSee((string) $standard->businesses()->count());
    }
}
