<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_an_owner_can_request_cancellation(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);

        $response = $this->actingAs($business->owner)->post('/subscription/cancel');

        $response->assertRedirect('/subscription');
        $this->assertNotNull($business->currentSubscription->fresh()->cancelled_at);
    }

    public function test_cancellation_does_not_delete_subscription_history(): void
    {
        $business = Business::factory()->create();
        $subscriptionId = $business->current_subscription_id;

        $this->actingAs($business->owner)->post('/subscription/cancel');

        $this->assertNotNull(Subscription::find($subscriptionId));
        $this->assertSame(1, $business->subscriptions()->count());
    }

    public function test_cancellation_does_not_delete_payment_records(): void
    {
        $business = Business::factory()->create();
        PaymentTransaction::factory()->for($business)->successful()->create();

        $this->actingAs($business->owner)->post('/subscription/cancel');

        $this->assertSame(1, $business->paymentTransactions()->count());
    }

    public function test_paid_access_remains_until_expiry_after_cancellation(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(10)]);

        $this->actingAs($business->owner)->post('/subscription/cancel');

        $this->assertTrue($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->fresh()->status);
    }

    public function test_cancelling_and_then_letting_it_expire_removes_access(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(5)]);

        $this->actingAs($business->owner)->post('/subscription/cancel');
        $this->travelTo(now()->addDays(6));
        $this->artisan('subscriptions:expire');

        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
        $this->assertSame(Subscription::STATUS_CANCELLED, $business->currentSubscription->fresh()->status);
    }

    public function test_a_cancelled_subscription_is_not_renewed_without_an_explicit_renewal_action(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(5)]);

        $this->actingAs($business->owner)->post('/subscription/cancel');
        $this->travelTo(now()->addDays(6));
        $this->artisan('subscriptions:expire');
        $this->artisan('subscriptions:expire'); // run again; still nothing renews on its own

        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
        $this->assertSame(1, $business->subscriptions()->count());
    }

    public function test_an_owner_can_resume_a_cancellation_before_expiry(): void
    {
        $business = Business::factory()->create();
        $this->actingAs($business->owner)->post('/subscription/cancel');

        $response = $this->actingAs($business->owner)->post('/subscription/resume');

        $response->assertRedirect('/subscription');
        $this->assertNull($business->currentSubscription->fresh()->cancelled_at);
    }

    public function test_a_guest_cannot_cancel_a_subscription(): void
    {
        $this->post('/subscription/cancel')->assertRedirect('/login');
    }
}
