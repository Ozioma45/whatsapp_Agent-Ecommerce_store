<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\PaymentFailed;
use App\Notifications\SubscriptionActivated;
use App\Notifications\SubscriptionExpired;
use App\Notifications\SubscriptionExpiringSoon;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionNotificationTest extends TestCase
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

    public function test_an_activation_notification_is_sent_on_successful_payment(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([
            'status' => true, 'data' => ['status' => 'success', 'reference' => $transaction->reference, 'amount' => $transaction->amount, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);

        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertSentTo($business->owner, SubscriptionActivated::class);
    }

    public function test_a_payment_failed_notification_is_sent_on_verification_failure(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'x'],
        ], 200)]);
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        Http::fake(["api.paystack.co/transaction/verify/{$transaction->reference}" => Http::response([
            'status' => true, 'data' => ['status' => 'failed', 'reference' => $transaction->reference, 'amount' => $transaction->amount, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);

        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertSentTo($business->owner, PaymentFailed::class);
    }

    public function test_an_expiring_soon_reminder_is_sent_once_within_the_window(): void
    {
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(2)]);

        $this->artisan('subscriptions:expire');

        Notification::assertSentToTimes($business->owner, SubscriptionExpiringSoon::class, 1);
        $this->assertNotNull($business->currentSubscription->fresh()->expiry_reminder_sent_at);
    }

    public function test_duplicate_scheduler_execution_does_not_send_duplicate_reminders(): void
    {
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(2)]);

        $this->artisan('subscriptions:expire');
        $this->artisan('subscriptions:expire');
        $this->artisan('subscriptions:expire');

        Notification::assertSentToTimes($business->owner, SubscriptionExpiringSoon::class, 1);
    }

    public function test_an_expired_notification_is_sent_when_a_subscription_actually_expires(): void
    {
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire');

        Notification::assertSentTo($business->owner, SubscriptionExpired::class);
    }

    public function test_a_notification_failure_does_not_corrupt_subscription_state(): void
    {
        // Simulate the mail layer throwing by pointing it at an invalid
        // mailer, rather than faking Notification — this proves the
        // try/catch around every ->notify() call really does protect the
        // surrounding transaction/command.
        config(['mail.default' => 'does-not-exist']);
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire')->assertExitCode(0);

        $this->assertSame(Subscription::STATUS_EXPIRED, $business->currentSubscription->fresh()->status);
    }
}
