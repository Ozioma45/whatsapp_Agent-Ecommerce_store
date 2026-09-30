<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentInitiationTest extends TestCase
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

    private function fakeSuccessfulInit(string $reference = 'PSK-FAKE-REF'): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/'.$reference,
                'access_code' => 'access_code_123',
                'reference' => $reference,
            ],
        ], 200)]);
    }

    public function test_an_authenticated_owner_can_initiate_payment_and_is_redirected_to_checkout(): void
    {
        $this->fakeSuccessfulInit();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);

        $response = $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://checkout.paystack.com/', $response->headers->get('Location'));
    }

    public function test_the_correct_price_is_sent_to_paystack_in_kobo(): void
    {
        $this->fakeSuccessfulInit();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail(); // seeded at 15000.00
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request['amount'] === 1500000
                && $request['currency'] === 'NGN';
        });
    }

    public function test_a_unique_reference_is_generated_and_stored(): void
    {
        $this->fakeSuccessfulInit('PSK-UNIQUE-1');
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);

        $transaction = PaymentTransaction::where('business_id', $business->id)->firstOrFail();
        $this->assertNotEmpty($transaction->reference);
        $this->assertSame(1, PaymentTransaction::where('reference', $transaction->reference)->count());

        Http::assertSent(fn (HttpRequest $request) => $request['reference'] === $transaction->reference);
    }

    public function test_missing_credentials_fail_safely_without_calling_paystack(): void
    {
        config(['services.paystack.secret_key' => null]);
        Http::fake();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);

        $response = $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);

        $response->assertRedirect('/subscription');
        Http::assertNothingSent();
        $this->assertSame(PaymentTransaction::STATUS_FAILED, PaymentTransaction::where('business_id', $business->id)->firstOrFail()->status);
        $this->assertSame(Plan::where('slug', Plan::STANDARD)->firstOrFail()->id, $business->fresh()->plan_id);
    }

    public function test_a_paystack_api_error_does_not_activate_a_subscription(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'error'], 400)]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $response = $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);

        $response->assertRedirect('/subscription');
        $this->assertSame($standard->id, $business->fresh()->plan_id);
        $this->assertSame(PaymentTransaction::STATUS_FAILED, PaymentTransaction::where('business_id', $business->id)->firstOrFail()->status);
    }

    public function test_a_client_submitted_amount_is_ignored(): void
    {
        $this->fakeSuccessfulInit();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id, 'amount' => 1]);

        $transaction = PaymentTransaction::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(1500000, $transaction->amount);
    }

    public function test_a_business_id_submitted_by_the_client_is_ignored(): void
    {
        $this->fakeSuccessfulInit();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $a = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $b = Business::factory()->create();

        $this->actingAs($a->owner)->post('/subscription/pay', ['plan_id' => $pro->id, 'business_id' => $b->id]);

        $transaction = PaymentTransaction::latest()->firstOrFail();
        $this->assertSame($a->id, $transaction->business_id);
        $this->assertNotSame($b->id, $transaction->business_id);
    }

    public function test_an_inactive_plan_cannot_be_paid_for(): void
    {
        $business = Business::factory()->create();
        $inactivePlan = Plan::factory()->create(['is_active' => false, 'price' => 5000]);

        $response = $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $inactivePlan->id]);

        $response->assertSessionHasErrors('plan_id');
    }

    public function test_an_invalid_plan_id_is_rejected(): void
    {
        $business = Business::factory()->create();

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => 999999])
            ->assertSessionHasErrors('plan_id');
    }

    public function test_a_free_plan_cannot_be_paid_for(): void
    {
        Http::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PRO)->firstOrFail()->id]);

        $response = $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $standard->id]);

        $response->assertRedirect('/subscription');
        Http::assertNothingSent();
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_guest_cannot_initiate_payment(): void
    {
        $this->post('/subscription/pay', ['plan_id' => 1])->assertRedirect('/login');
    }

    public function test_initiating_payment_creates_a_pending_subscription_request_but_does_not_change_the_plan(): void
    {
        $this->fakeSuccessfulInit();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $premium->id]);

        $this->assertSame($standard->id, $business->fresh()->plan_id);
        $this->assertSame(1, $business->subscriptions()->where('status', Subscription::STATUS_PENDING)->count());
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }
}
