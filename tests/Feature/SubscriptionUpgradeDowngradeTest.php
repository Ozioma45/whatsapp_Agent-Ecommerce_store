<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionUpgradeDowngradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config([
            'services.paystack.secret_key' => 'sk_test_fake',
            'services.paystack.payment_url' => 'https://api.paystack.co',
        ]);
    }

    private function fakeInit(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);
    }

    private function fakeVerify(string $reference, int $amount): void
    {
        Http::fake(["api.paystack.co/transaction/verify/{$reference}" => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => $reference, 'amount' => $amount, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);
    }

    private function payAndVerify(Business $business, Plan $plan): PaymentTransaction
    {
        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $plan->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        return $transaction->fresh();
    }

    // --- Upgrade ------------------------------------------------------

    public function test_the_existing_plan_remains_active_until_upgrade_payment_succeeds(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $premium->id]);

        $this->assertSame($standard->id, $business->fresh()->plan_id);
    }

    public function test_a_failed_upgrade_payment_does_not_change_the_plan(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $premium->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([
            'status' => true, 'data' => ['status' => 'failed', 'reference' => $transaction->reference, 'amount' => $transaction->amount, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        $this->assertSame($standard->id, $business->fresh()->plan_id);
    }

    public function test_successful_upgrade_activates_the_new_plan_immediately(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->payAndVerify($business, $premium);

        $this->assertSame($premium->id, $business->fresh()->plan_id);
        $this->assertTrue($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
        $this->assertSame(Subscription::STATUS_ACTIVE, $business->fresh()->currentSubscription->status);
    }

    public function test_upgrade_preserves_the_previous_subscription_as_history(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $originalId = $business->current_subscription_id;

        $this->payAndVerify($business, $premium);

        $this->assertNotNull(Subscription::find($originalId));
        $this->assertSame(Subscription::STATUS_CANCELLED, Subscription::find($originalId)->status);
        $this->assertSame($standard->id, Subscription::find($originalId)->plan_id);
    }

    public function test_upgrade_starts_a_fresh_period_from_now_with_no_proration(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        // Plenty of time left on the current (cheaper) plan.
        $business->currentSubscription->update(['expires_at' => now()->addDays(25)]);

        $this->payAndVerify($business, $premium);

        $this->assertSame(now()->toDateString(), $business->fresh()->currentSubscription->starts_at->toDateString());
    }

    // --- Downgrade --------------------------------------------------------
    // Downgrade targets must still be a *paid* plan — Standard is free, and
    // Paystack is never invoked for a ₦0 plan (see PaymentService::initiate()),
    // so these use Premium → Pro (both paid, Pro cheaper) throughout.

    public function test_downgrade_does_not_immediately_revoke_current_paid_access(): void
    {
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $premium->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(15)]);

        $this->payAndVerify($business, $pro);

        $this->assertSame($premium->id, $business->fresh()->plan_id);
        $this->assertTrue($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_a_pending_downgrade_is_preserved_as_a_scheduled_change(): void
    {
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $premium->id]);
        $expiry = now()->addDays(15);
        $business->currentSubscription->update(['expires_at' => $expiry]);

        $transaction = $this->payAndVerify($business, $pro);

        $scheduled = Subscription::find($transaction->subscription_id);
        $this->assertSame(Subscription::STATUS_PENDING, $scheduled->status);
        $this->assertTrue($scheduled->isScheduledChange());
        $this->assertSame($expiry->toDateString(), $scheduled->starts_at->toDateString());
        $this->assertSame($pro->id, $scheduled->plan_id);
    }

    public function test_downgrade_takes_effect_once_the_current_period_ends(): void
    {
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $premium->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(15)]);

        $this->payAndVerify($business, $pro);

        // Time actually passes, past the premium period's real expiry —
        // the scheduled downgrade's starts_at was fixed to that same date
        // when it was scheduled, so both become due together.
        $this->travelTo(now()->addDays(16));

        $this->artisan('subscriptions:expire');

        $this->assertSame($pro->id, $business->fresh()->plan_id);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
        $this->assertSame(Subscription::STATUS_EXPIRED, Subscription::where('plan_id', $premium->id)->where('business_id', $business->id)->first()->status);
    }

    public function test_a_failed_downgrade_payment_does_not_alter_the_current_subscription(): void
    {
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $premium->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(15)]);
        $originalSubscriptionId = $business->current_subscription_id;

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([
            'status' => true, 'data' => ['status' => 'failed', 'reference' => $transaction->reference, 'amount' => $transaction->amount, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        $business->refresh();
        $this->assertSame($premium->id, $business->plan_id);
        $this->assertSame($originalSubscriptionId, $business->current_subscription_id);
    }
}
