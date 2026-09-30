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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_fake';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config([
            'services.paystack.secret_key' => self::SECRET,
            'services.paystack.payment_url' => 'https://api.paystack.co',
        ]);
    }

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $content = json_encode($payload);
        $signature = hash_hmac('sha512', $content, $secret ?? self::SECRET);

        return $this->withHeaders(['x-paystack-signature' => $signature])->postJson('/webhooks/paystack', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeSuccessPayload(string $reference, int $amount, string $currency = 'NGN'): array
    {
        return [
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'id' => 555111,
                'status' => 'success',
                'channel' => 'card',
            ],
        ];
    }

    private function fakeVerify(string $reference, string $status, int $amount, string $currency = 'NGN'): void
    {
        Http::fake(["api.paystack.co/transaction/verify/{$reference}" => Http::response([
            'status' => true,
            'data' => ['status' => $status, 'reference' => $reference, 'amount' => $amount, 'currency' => $currency, 'id' => 555111, 'channel' => 'card'],
        ], 200)]);
    }

    public function test_a_valid_signature_and_successful_event_activates_the_subscription(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $transaction = $this->pendingTransaction($business, $premium, 4500000);
        $this->fakeVerify($transaction->reference, 'success', 4500000);

        $this->postWebhook($this->chargeSuccessPayload($transaction->reference, 4500000))->assertOk();

        $this->assertSame(PaymentTransaction::STATUS_SUCCESSFUL, $transaction->fresh()->status);
        $this->assertSame($premium->id, $business->fresh()->plan_id);
        $this->assertTrue($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_an_invalid_signature_is_rejected_and_never_processes_the_event(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000);

        $this->postWebhook($this->chargeSuccessPayload($transaction->reference, 1500000), secret: 'wrong-secret')
            ->assertStatus(401);

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $business = Business::factory()->create();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);

        $this->postJson('/webhooks/paystack', $this->chargeSuccessPayload($transaction->reference, 1500000))
            ->assertStatus(401);
    }

    public function test_an_unsupported_event_is_safely_ignored(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);

        $this->postWebhook(['event' => 'charge.failed', 'data' => ['reference' => $transaction->reference]])
            ->assertOk();

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_duplicate_webhook_delivery_is_idempotent(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000);
        $payload = $this->chargeSuccessPayload($transaction->reference, 1500000);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(2, $business->subscriptions()->count());
        $this->assertSame(1, $business->subscriptions()->where('status', Subscription::STATUS_ACTIVE)->count());
    }

    public function test_an_unknown_transaction_reference_is_handled_safely(): void
    {
        $this->postWebhook($this->chargeSuccessPayload('NO-SUCH-REFERENCE', 1500000))->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_an_incorrect_amount_in_the_webhook_event_is_rejected(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        // The webhook payload claims success, but Paystack's own
        // verification (the actual source of truth) disagrees on amount.
        $this->fakeVerify($transaction->reference, 'success', 100);

        $this->postWebhook($this->chargeSuccessPayload($transaction->reference, 1500000))->assertOk();

        $this->assertSame(PaymentTransaction::STATUS_FAILED, $transaction->fresh()->status);
        $this->assertNotSame($pro->id, $business->fresh()->plan_id);
    }

    public function test_webhook_and_callback_do_not_duplicate_activation(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000);

        $this->postWebhook($this->chargeSuccessPayload($transaction->reference, 1500000))->assertOk();
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference)->assertRedirect('/subscription');

        $this->assertSame(2, $business->subscriptions()->count());
        $this->assertSame(1, $business->subscriptions()->where('status', Subscription::STATUS_ACTIVE)->count());
    }

    public function test_no_secrets_appear_in_the_webhook_response(): void
    {
        $business = Business::factory()->create();
        $business->currentSubscription->update(['status' => Subscription::STATUS_ACTIVE]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $transaction = $this->pendingTransaction($business, $pro, 1500000);
        $this->fakeVerify($transaction->reference, 'success', 1500000);

        $response = $this->postWebhook($this->chargeSuccessPayload($transaction->reference, 1500000));

        $response->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }
}
