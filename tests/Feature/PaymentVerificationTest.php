<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentVerificationTest extends TestCase
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

    /**
     * A pending transaction, and the pending subscription request it's
     * for, exactly as PaymentService::initiate() would create them.
     */
    private function pendingTransaction(Business $business, Plan $plan, int $amount): PaymentTransaction
    {
        $request = app(SubscriptionService::class)->requestPlanChange($business, $plan);

        return PaymentTransaction::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'subscription_id' => $request->id,
            'amount' => $amount,
            'currency' => 'NGN',
            'status' => PaymentTransaction::STATUS_PENDING,
        ]);
    }

    private function fakeVerify(string $reference, string $status, int $amount, string $currency = 'NGN'): void
    {
        Http::fake(["api.paystack.co/transaction/verify/{$reference}" => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'status' => $status,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'id' => 987654321,
                'channel' => 'card',
            ],
        ], 200)]);
    }

    private function visitCallback(Business $business, string $reference)
    {
        return $this->actingAs($business->owner)->get('/subscription/callback?reference='.$reference);
    }

    public function test_a_successful_verification_activates_the_correct_subscription(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000);

        $this->visitCallback($business, $transaction->reference)->assertRedirect('/subscription');

        $business->refresh();
        $this->assertSame($pro->id, $business->plan_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $business->currentSubscription->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCESSFUL, $transaction->fresh()->status);
        $this->assertTrue($business->hasFeature(Feature::CUSTOM_BRANDING));
    }

    public function test_an_incorrect_reference_from_paystack_is_rejected(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        // Paystack's own record disagrees about which reference this is.
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([
            'status' => true, 'data' => ['status' => 'success', 'reference' => 'SOMETHING-ELSE', 'amount' => 1500000, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);

        $this->visitCallback($business, $transaction->reference);

        $this->assertSame(PaymentTransaction::STATUS_FAILED, $transaction->fresh()->status);
        $this->assertSame(Plan::where('slug', Plan::STANDARD)->firstOrFail()->id, $business->fresh()->plan_id);
    }

    public function test_an_incorrect_amount_is_rejected(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 100); // far less than owed

        $this->visitCallback($business, $transaction->reference);

        $this->assertSame(PaymentTransaction::STATUS_FAILED, $transaction->fresh()->status);
        $this->assertSame('amount_mismatch', $transaction->fresh()->failure_reason);
        $this->assertFalse($business->fresh()->hasFeature(Feature::CUSTOM_BRANDING));
    }

    public function test_an_incorrect_currency_is_rejected(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000, 'USD');

        $this->visitCallback($business, $transaction->reference);

        $this->assertSame(PaymentTransaction::STATUS_FAILED, $transaction->fresh()->status);
        $this->assertSame('currency_mismatch', $transaction->fresh()->failure_reason);
    }

    public function test_a_failed_payment_does_not_activate_a_subscription(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'failed', 1500000);

        $this->visitCallback($business, $transaction->reference);

        $this->assertSame(PaymentTransaction::STATUS_FAILED, $transaction->fresh()->status);
        $this->assertSame($standard->id, $business->fresh()->plan_id);
    }

    public function test_a_pending_payment_does_not_activate_a_subscription(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'abandoned', 1500000);

        $this->visitCallback($business, $transaction->reference);

        $this->assertSame(PaymentTransaction::STATUS_ABANDONED, $transaction->fresh()->status);
        $this->assertSame($standard->id, $business->fresh()->plan_id);
    }

    public function test_an_unavailable_verification_service_does_not_activate_a_subscription(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([], 500)]);

        $this->visitCallback($business, $transaction->reference);

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertSame($standard->id, $business->fresh()->plan_id);
    }

    public function test_repeated_verification_does_not_create_duplicate_subscription_records(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000);

        $this->visitCallback($business, $transaction->reference);
        $this->visitCallback($business, $transaction->reference);
        $this->visitCallback($business, $transaction->reference);

        $this->assertSame(2, $business->subscriptions()->count()); // original standard + new pro
        $this->assertSame(1, $business->subscriptions()->where('status', Subscription::STATUS_ACTIVE)->count());
    }

    public function test_a_transaction_belonging_to_another_business_cannot_be_used_by_a_different_owner(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($a, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000);

        $response = $this->visitCallback($b, $transaction->reference);

        $response->assertRedirect('/subscription');
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertNotSame($pro->id, $b->fresh()->plan_id);
    }
}
