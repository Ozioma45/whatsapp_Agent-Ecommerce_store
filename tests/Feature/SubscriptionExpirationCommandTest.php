<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionExpirationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_an_active_expired_subscription_becomes_expired(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire')->assertExitCode(0);

        $this->assertSame(Subscription::STATUS_EXPIRED, $business->currentSubscription->fresh()->status);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_a_future_subscription_remains_active(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addMonth()]);

        $this->artisan('subscriptions:expire');

        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->fresh()->status);
        $this->assertTrue($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_running_the_command_twice_is_safe_and_does_not_double_act(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire');
        $firstUpdatedAt = $business->currentSubscription->fresh()->updated_at;

        $this->artisan('subscriptions:expire');

        $this->assertSame(Subscription::STATUS_EXPIRED, $business->currentSubscription->fresh()->status);
        $this->assertTrue($firstUpdatedAt->equalTo($business->currentSubscription->fresh()->updated_at));
    }

    public function test_an_expired_subscription_a_business_had_cancelled_becomes_cancelled_not_expired(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->subDay(), 'cancelled_at' => now()->subDays(5)]);

        $this->artisan('subscriptions:expire');

        $this->assertSame(Subscription::STATUS_CANCELLED, $business->currentSubscription->fresh()->status);
    }

    public function test_one_businesss_expiration_never_affects_another_business(): void
    {
        $a = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $a->currentSubscription->update(['expires_at' => now()->subDay()]);
        $b->currentSubscription->update(['expires_at' => now()->addMonth()]);

        $this->artisan('subscriptions:expire');

        $this->assertSame(Subscription::STATUS_EXPIRED, $a->currentSubscription->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $b->currentSubscription->fresh()->status);
        $this->assertTrue($b->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_suspended_subscriptions_are_untouched_by_the_expiration_command(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['status' => Subscription::STATUS_SUSPENDED, 'expires_at' => now()->addMonth()]);

        $this->artisan('subscriptions:expire');

        $this->assertSame(Subscription::STATUS_SUSPENDED, $business->currentSubscription->fresh()->status);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_a_subscription_with_no_expiry_date_is_never_expired(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => null]);

        $this->artisan('subscriptions:expire');

        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->fresh()->status);
    }
}
