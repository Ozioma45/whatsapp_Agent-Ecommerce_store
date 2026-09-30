<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    /**
     * Build a full plan-update payload matching what the real form always
     * submits (one entry per feature the plan has, since every feature
     * renders its own checkbox) — starting from the plan's current
     * entitlements and applying the given overrides on top.
     */
    private function planPayload(Plan $plan, array $overrides = []): array
    {
        $features = [];

        foreach ($plan->features as $feature) {
            $features[$feature->id] = [
                'enabled' => $feature->pivot->enabled ? '1' : '0',
                'limit' => $feature->pivot->limit,
            ];
        }

        foreach ($overrides as $featureId => $data) {
            $features[$featureId] = array_merge($features[$featureId] ?? [], $data);
        }

        return [
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => (string) $plan->price,
            'is_active' => $plan->is_active ? '1' : '0',
            'features' => $features,
        ];
    }

    public function test_admin_can_view_plans(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/plans')
            ->assertOk()->assertSee('Standard')->assertSee('Pro')->assertSee('Premium');
    }

    public function test_admin_can_update_plan_name_and_description_safely(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();

        $payload = array_merge($this->planPayload($plan), [
            'name' => 'Standard Updated',
            'description' => 'New description',
        ]);

        $response = $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $payload);

        $response->assertRedirect(route('admin.plans.show', $plan));
        $plan->refresh();
        $this->assertSame('Standard Updated', $plan->name);
        $this->assertSame('New description', $plan->description);
        $this->assertSame('standard', $plan->slug);
    }

    public function test_admin_can_change_a_features_enabled_state(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $feature = Feature::where('key', Feature::CUSTOM_BRANDING)->firstOrFail();

        $payload = $this->planPayload($plan, [$feature->id => ['enabled' => '1']]);
        $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $payload);

        $this->assertTrue((bool) $plan->features()->where('feature_id', $feature->id)->first()->pivot->enabled);
    }

    public function test_admin_can_change_a_features_limit(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $feature = Feature::where('key', Feature::PRODUCTS_LIMIT)->firstOrFail();

        $payload = $this->planPayload($plan, [$feature->id => ['enabled' => '1', 'limit' => '50']]);
        $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $payload);

        $pivot = $plan->features()->where('feature_id', $feature->id)->first()->pivot;
        $this->assertSame(50, $pivot->limit);
    }

    public function test_a_blank_limit_means_unlimited(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $feature = Feature::where('key', Feature::PRODUCTS_LIMIT)->firstOrFail();

        $payload = $this->planPayload($plan, [$feature->id => ['enabled' => '1', 'limit' => '']]);
        $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $payload);

        $pivot = $plan->features()->where('feature_id', $feature->id)->first()->pivot;
        $this->assertNull($pivot->limit);
    }

    public function test_plan_changes_affect_entitlement_checks(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::with('features')->where('slug', Plan::STANDARD)->firstOrFail();
        $feature = Feature::where('key', Feature::PRODUCTS_LIMIT)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $plan->id]);

        $this->assertSame(20, $business->featureLimit(Feature::PRODUCTS_LIMIT));

        $payload = $this->planPayload($plan, [$feature->id => ['enabled' => '1', 'limit' => '999']]);
        $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", $payload);

        $this->assertSame(999, $business->featureLimit(Feature::PRODUCTS_LIMIT));
    }

    public function test_a_plan_assigned_to_businesses_cannot_be_deleted(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->admin();
        $plan = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        Business::factory()->create(['plan_id' => $plan->id]);

        $response = $this->actingAs($admin)->delete("/admin/plans/{$plan->id}");

        $response->assertRedirect(route('admin.plans.show', $plan));
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_an_unused_plan_can_be_deleted(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();

        $response = $this->actingAs($admin)->delete("/admin/plans/{$plan->id}");

        $response->assertRedirect(route('admin.plans.index'));
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }
}
