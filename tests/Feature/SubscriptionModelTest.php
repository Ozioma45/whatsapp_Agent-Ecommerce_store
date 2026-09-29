<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The subscription data model itself: creation, association, and history
 * preservation across plan changes.
 */
class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_every_new_business_gets_an_initial_active_subscription(): void
    {
        $plan = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $plan->id]);

        $subscription = $business->currentSubscription;

        $this->assertNotNull($subscription);
        $this->assertSame($business->id, $subscription->business_id);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->isInGoodStanding());
    }

    public function test_a_subscription_is_associated_with_the_correct_business(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();

        $this->assertSame($a->id, $a->currentSubscription->business_id);
        $this->assertSame($b->id, $b->currentSubscription->business_id);
        $this->assertNotSame($a->currentSubscription->id, $b->currentSubscription->id);
    }

    public function test_pre_existing_businesses_keep_their_plan_assignment(): void
    {
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);

        $this->assertSame($pro->id, $business->plan_id);
        $this->assertSame($pro->id, $business->currentSubscription->plan_id);
    }

    public function test_changing_plans_preserves_subscription_history(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $originalSubscriptionId = $business->current_subscription_id;

        app(SubscriptionService::class)->assignPlanDirectly($business, $pro, $admin);
        $business->refresh();

        $this->assertSame(2, $business->subscriptions()->count());
        $this->assertNotSame($originalSubscriptionId, $business->current_subscription_id);
        $this->assertSame(Subscription::STATUS_CANCELLED, Subscription::find($originalSubscriptionId)->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->status);
        $this->assertSame($pro->id, $business->plan_id);
    }

    public function test_dates_are_cast_to_carbon_instances(): void
    {
        $subscription = Subscription::factory()->create([
            'starts_at' => '2026-01-01',
            'expires_at' => '2026-12-31',
        ]);

        $this->assertInstanceOf(Carbon::class, $subscription->starts_at);
        $this->assertInstanceOf(Carbon::class, $subscription->expires_at);
    }

    public function test_a_subscription_survives_its_plan_being_deleted(): void
    {
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->create(['plan_id' => $plan->id]);

        $plan->delete();

        $this->assertNotNull($subscription->fresh());
        $this->assertNull($subscription->fresh()->plan_id);
    }

    public function test_a_business_with_no_subscription_record_is_treated_as_active(): void
    {
        $business = Business::factory()->create();
        $business->currentSubscription()->dissociate();
        $business->save();

        $this->assertTrue($business->fresh()->hasActiveSubscriptionStanding());
    }
}
