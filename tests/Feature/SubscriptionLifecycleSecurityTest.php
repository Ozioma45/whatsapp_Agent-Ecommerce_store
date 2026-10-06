<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Section 14: every lifecycle action is scoped to the authenticated
 * business, and nothing a browser submits (price, amount, business id,
 * subscription id, plan id, billing period, or dates) is ever trusted
 * over the database.
 */
class SubscriptionLifecycleSecurityTest extends TestCase
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

    public function test_an_owner_cannot_cancel_another_businesss_subscription(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);

        // There is no subscription-id parameter at all to manipulate — the
        // route always acts on the authenticated user's own business.
        $this->actingAs($a->owner)->post('/subscription/cancel');

        $this->assertNull($b->currentSubscription->fresh()->cancelled_at);
    }

    public function test_an_owner_cannot_renew_or_pay_for_another_business(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);

        // Attempt to smuggle another business's id in alongside plan_id.
        $this->actingAs($a->owner)->post('/subscription/pay', ['plan_id' => $pro->id, 'business_id' => $b->id]);

        $transaction = PaymentTransaction::latest()->first();
        $this->assertSame($a->id, $transaction->business_id);
        $this->assertNotSame($b->id, $transaction->business_id);
        $this->assertSame(Plan::where('slug', Plan::STANDARD)->firstOrFail()->id, $b->fresh()->plan_id);
    }

    public function test_an_owner_cannot_view_another_businesss_payment_history(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        PaymentTransaction::factory()->for($b)->successful()->create(['reference' => 'PSK-OTHER-BIZ-REF']);

        $this->actingAs($a->owner)->get('/subscription')->assertDontSee('PSK-OTHER-BIZ-REF');
    }

    public function test_a_callback_cannot_use_a_subscription_id_to_reach_another_businesss_transaction(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $request = app(SubscriptionService::class)->requestPlanChange($b, $pro);
        $transaction = PaymentTransaction::factory()->create([
            'business_id' => $b->id, 'plan_id' => $pro->id, 'subscription_id' => $request->id,
            'amount' => (int) ($pro->price * 100), 'status' => PaymentTransaction::STATUS_PENDING,
        ]);
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([
            'status' => true, 'data' => ['status' => 'success', 'reference' => $transaction->reference, 'amount' => $transaction->amount, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);

        // A actingAs owner tries to use B's own payment reference.
        $this->actingAs($a->owner)->get('/subscription/callback?reference='.$transaction->reference);

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertSame(Plan::where('slug', Plan::STANDARD)->firstOrFail()->id, $b->fresh()->plan_id);
    }

    public function test_an_owner_cannot_upgrade_another_business_via_the_admin_assign_route(): void
    {
        $owner = Business::factory()->create()->owner;
        $other = Business::factory()->create();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $this->actingAs($owner)->patch("/admin/businesses/{$other->handle}/plan", ['plan_id' => $premium->id])
            ->assertForbidden();

        $this->assertNotSame($premium->id, $other->fresh()->plan_id);
    }

    public function test_a_manipulated_plan_id_that_does_not_exist_is_rejected(): void
    {
        $business = Business::factory()->create();

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => 999999])
            ->assertSessionHasErrors('plan_id');
    }

    public function test_a_manipulated_billing_period_is_rejected(): void
    {
        $business = Business::factory()->create();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id, 'billing_period' => 'weekly'])
            ->assertSessionHasErrors('billing_period');
    }

    public function test_a_browser_submitted_amount_never_overrides_the_database_price(): void
    {
        $business = Business::factory()->create();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id, 'amount' => 1]);

        $this->assertSame((int) round($pro->price * 100), PaymentTransaction::latest()->first()->amount);
    }

    public function test_a_manipulated_expiry_date_on_the_owner_facing_routes_is_impossible_there_is_no_such_field(): void
    {
        // The owner-facing pay/cancel/resume/request routes accept no
        // date fields at all — dates are only ever computed server-side
        // (see SubscriptionService) or set by an admin on the admin-only
        // routes. This test documents that by asserting the field is
        // simply ignored if someone tries to submit it anyway.
        $business = Business::factory()->create();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);

        $this->actingAs($business->owner)->post('/subscription/pay', [
            'plan_id' => $pro->id,
            'starts_at' => '2000-01-01',
            'expires_at' => '2099-01-01',
        ]);

        $subscription = Subscription::where('business_id', $business->id)->where('status', Subscription::STATUS_PENDING)->first();
        $this->assertNull($subscription->starts_at);
        $this->assertNull($subscription->expires_at);
    }

    public function test_non_admin_cannot_suspend_or_reactivate_any_subscription(): void
    {
        $owner = Business::factory()->create()->owner;
        $other = Business::factory()->create();

        $this->actingAs($owner)->patch("/admin/businesses/{$other->handle}/subscription/suspend")->assertForbidden();
        $this->actingAs($owner)->patch("/admin/businesses/{$other->handle}/subscription/reactivate")->assertForbidden();
    }
}
