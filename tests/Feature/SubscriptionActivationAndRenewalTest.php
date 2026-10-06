<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Activation and renewal: the dates/billing-period a verified payment
 * produces, and the "renew after current expiry, not from now" rule.
 */
class SubscriptionActivationAndRenewalTest extends TestCase
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

    private function fakeVerify(string $reference, string $status, int $amount, string $currency = 'NGN'): void
    {
        Http::fake(["api.paystack.co/transaction/verify/{$reference}" => Http::response([
            'status' => true,
            'data' => ['status' => $status, 'reference' => $reference, 'amount' => $amount, 'currency' => $currency, 'id' => 1, 'channel' => 'card'],
        ], 200)]);
    }

    private function fakeInit(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);
    }

    private function pay(Business $business, Plan $plan, ?string $billingPeriod = null): void
    {
        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', array_filter([
            'plan_id' => $plan->id,
            'billing_period' => $billingPeriod,
        ]));
    }

    private function completePayment(Business $business): void
    {
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'success', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);
    }

    public function test_verified_payment_creates_the_correct_subscription_period(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->pay($business, $pro);
        $this->completePayment($business);

        $subscription = $business->fresh()->currentSubscription;
        $this->assertSame($pro->id, $subscription->plan_id);
        $this->assertSame('monthly', $subscription->billing_period);
        $this->assertSame(now()->toDateString(), $subscription->starts_at->toDateString());
        $this->assertSame(now()->addMonthsNoOverflow(1)->toDateString(), $subscription->expires_at->toDateString());
    }

    public function test_the_correct_billing_period_is_stored_for_a_yearly_payment(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->pay($business, $pro, 'yearly');
        $this->completePayment($business);

        $subscription = $business->fresh()->currentSubscription;
        $this->assertSame('yearly', $subscription->billing_period);
        $this->assertSame(now()->addYearsNoOverflow(1)->toDateString(), $subscription->expires_at->toDateString());

        // The yearly amount charged was 12x the monthly database price.
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->assertSame((int) round($pro->price * 100) * 12, $transaction->amount);
    }

    public function test_existing_subscription_history_remains_intact_after_activation(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $originalId = $business->current_subscription_id;

        $this->pay($business, $pro);
        $this->completePayment($business);

        $this->assertSame(2, $business->subscriptions()->count());
        $this->assertSame(Subscription::STATUS_CANCELLED, Subscription::find($originalId)->status);
    }

    public function test_a_renewal_of_the_same_plan_begins_after_the_current_expiry_not_today(): void
    {
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);
        $business->currentSubscription->update([
            'billing_period' => 'monthly',
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->addDays(20), // still active, not yet expired
        ]);
        $expectedStart = $business->currentSubscription->expires_at->toDateString();

        $this->pay($business, $pro);
        $this->completePayment($business);

        $subscription = $business->fresh()->currentSubscription;
        $this->assertSame($expectedStart, $subscription->starts_at->toDateString());
        $this->assertSame(
            Carbon::parse($expectedStart)->addMonthsNoOverflow(1)->toDateString(),
            $subscription->expires_at->toDateString()
        );
    }

    public function test_a_renewal_of_an_already_expired_plan_begins_from_the_activation_date(): void
    {
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);
        $business->currentSubscription->update([
            'status' => Subscription::STATUS_EXPIRED,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subDays(5), // already expired
        ]);

        $this->pay($business, $pro);
        $this->completePayment($business);

        $subscription = $business->fresh()->currentSubscription;
        $this->assertSame(now()->toDateString(), $subscription->starts_at->toDateString());
    }

    public function test_a_failed_renewal_does_not_extend_the_existing_expiry(): void
    {
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);
        $originalExpiry = now()->addDays(20)->toDateString();
        $business->currentSubscription->update(['billing_period' => 'monthly', 'expires_at' => $originalExpiry]);
        $originalSubscriptionId = $business->current_subscription_id;

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'failed', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        $business->refresh();
        $this->assertSame($originalSubscriptionId, $business->current_subscription_id);
        $this->assertSame($originalExpiry, $business->currentSubscription->expires_at->toDateString());
    }

    public function test_duplicate_webhook_and_callback_cannot_create_duplicate_renewal_subscriptions(): void
    {
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);

        $this->pay($business, $pro);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'success', $transaction->amount);

        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        $this->assertSame(2, $business->subscriptions()->count());
        $this->assertSame(1, $business->subscriptions()->where('status', Subscription::STATUS_ACTIVE)->count());
    }

    public function test_renewal_payment_not_completed_leaves_existing_access_valid(): void
    {
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(20)]);

        $this->pay($business, $pro); // initiated, never verified

        $this->assertTrue($business->fresh()->hasFeature(Feature::CUSTOM_BRANDING));
    }
}
