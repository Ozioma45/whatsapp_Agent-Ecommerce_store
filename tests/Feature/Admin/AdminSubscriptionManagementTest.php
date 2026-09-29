<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_admin_can_view_pending_requests(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        app(SubscriptionService::class)->requestPlanChange($business, $pro);

        $this->actingAs($admin)->get('/admin/subscriptions')
            ->assertOk()
            ->assertSee($business->name)
            ->assertSee('Pro');
    }

    public function test_admin_can_approve_a_request(): void
    {
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $request = app(SubscriptionService::class)->requestPlanChange($business, $pro);

        $response = $this->actingAs($admin)->patch("/admin/subscriptions/{$request->id}/approve", [
            'expires_at' => now()->addYear()->toDateString(),
        ]);

        $response->assertRedirect('/admin/subscriptions');
        $business->refresh();
        $this->assertSame($pro->id, $business->plan_id);
        $this->assertSame($request->id, $business->current_subscription_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->decided_by);
        $this->assertNotNull($request->fresh()->expires_at);
    }

    public function test_admin_can_reject_a_request_without_changing_the_plan(): void
    {
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $request = app(SubscriptionService::class)->requestPlanChange($business, $premium);

        $this->actingAs($admin)->patch("/admin/subscriptions/{$request->id}/reject");

        $this->assertSame($standard->id, $business->fresh()->plan_id);
        $this->assertSame(Subscription::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->decided_by);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_admin_can_assign_a_plan_directly_with_dates(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $response = $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", [
            'plan_id' => $premium->id,
            'starts_at' => now()->toDateString(),
            'expires_at' => now()->addMonths(6)->toDateString(),
        ]);

        $response->assertRedirect(route('admin.businesses.show', $business));
        $business->refresh();
        $this->assertSame($premium->id, $business->plan_id);
        $this->assertNotNull($business->currentSubscription->expires_at);
        $this->assertTrue($business->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_admin_can_suspend_and_reactivate_a_subscription(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $this->assertTrue($business->hasFeature(Feature::AI_ASSISTANT));

        $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/subscription/suspend")
            ->assertRedirect(route('admin.businesses.show', $business));

        $this->assertSame(Subscription::STATUS_SUSPENDED, $business->currentSubscription->fresh()->status);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));

        $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/subscription/reactivate")
            ->assertRedirect(route('admin.businesses.show', $business));

        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->fresh()->status);
        $this->assertTrue($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_non_admin_users_cannot_access_admin_subscription_routes(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $request = app(SubscriptionService::class)->requestPlanChange($business, Plan::where('slug', Plan::PRO)->firstOrFail());

        $this->actingAs($owner)->get('/admin/subscriptions')->assertForbidden();
        $this->actingAs($owner)->patch("/admin/subscriptions/{$request->id}/approve")->assertForbidden();
        $this->actingAs($owner)->patch("/admin/subscriptions/{$request->id}/reject")->assertForbidden();
        $this->actingAs($owner)->patch("/admin/businesses/{$business->handle}/subscription/suspend")->assertForbidden();
    }

    public function test_an_invalid_expiry_before_start_is_rejected(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $response = $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", [
            'plan_id' => $premium->id,
            'starts_at' => now()->toDateString(),
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $response->assertSessionHasErrors('expires_at');
    }

    public function test_an_invalid_plan_id_is_rejected_on_direct_assignment(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create();

        $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => 999999])
            ->assertSessionHasErrors('plan_id');
    }

    public function test_subscription_history_is_preserved_across_multiple_changes(): void
    {
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => $pro->id]);
        $this->actingAs($admin)->patch("/admin/businesses/{$business->handle}/plan", ['plan_id' => $premium->id]);

        $this->assertSame(3, $business->subscriptions()->count());
        $this->assertSame(2, $business->subscriptions()->where('status', Subscription::STATUS_CANCELLED)->count());
        $this->assertSame($premium->id, $business->fresh()->plan_id);

        $response = $this->actingAs($admin)->get("/admin/businesses/{$business->handle}");
        $response->assertOk();
        // History is rendered most-recent-first.
        $response->assertSeeInOrder(['Premium', 'Pro', 'Standard']);
    }
}
