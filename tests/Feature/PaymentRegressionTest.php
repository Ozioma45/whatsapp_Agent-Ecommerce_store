<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Support\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression coverage: Phase 10B must not disturb the existing manual
 * subscription workflow, entitlement rules, or tenant isolation.
 */
class PaymentRegressionTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_a_failed_payment_never_grants_paid_features(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $request = app(SubscriptionService::class)->requestPlanChange($business, $premium);
        $transaction = PaymentTransaction::factory()->create([
            'business_id' => $business->id, 'plan_id' => $premium->id, 'subscription_id' => $request->id,
            'amount' => 4500000, 'status' => PaymentTransaction::STATUS_PENDING,
        ]);
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([
            'status' => true, 'data' => ['status' => 'failed', 'reference' => $transaction->reference, 'amount' => 4500000, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);

        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_paid_entitlements_are_available_only_after_verified_payment_not_merely_initiation(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $premium->id]);

        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_existing_manual_admin_approval_still_works_after_paystack_integration(): void
    {
        $admin = $this->admin();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $request = app(SubscriptionService::class)->requestPlanChange($business, $pro);

        $this->actingAs($admin)->patch("/admin/subscriptions/{$request->id}/approve")->assertRedirect('/admin/subscriptions');

        $this->assertSame($pro->id, $business->fresh()->plan_id);
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_business_never_sees_another_businesss_payment_history_on_its_own_subscription_page(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        PaymentTransaction::factory()->for($b)->successful()->create(['reference' => 'PSK-SECRET-REF-B']);

        $this->actingAs($a->owner)->get('/subscription')
            ->assertOk()
            ->assertDontSee('PSK-SECRET-REF-B');
    }

    public function test_tenant_isolation_holds_across_subscriptions_and_payments_together(): void
    {
        $a = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);

        $this->assertFalse($a->hasFeature(Feature::AI_ASSISTANT));
        $this->assertTrue($b->hasFeature(Feature::AI_ASSISTANT));
        $this->assertNotSame($a->current_subscription_id, $b->current_subscription_id);
    }
}
