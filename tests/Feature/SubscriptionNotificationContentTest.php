<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PaymentFailed;
use App\Notifications\SubscriptionActivated;
use App\Notifications\SubscriptionExpired;
use App\Notifications\SubscriptionExpiringSoon;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 10D: notification content, the configurable reminder window, and
 * tenant isolation of every lifecycle notification.
 */
class SubscriptionNotificationContentTest extends TestCase
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

    private function fakeVerify(string $reference, string $status, int $amount): void
    {
        Http::fake(["api.paystack.co/transaction/verify/{$reference}" => Http::response([
            'status' => true,
            'data' => ['status' => $status, 'reference' => $reference, 'amount' => $amount, 'currency' => 'NGN', 'id' => 1, 'channel' => 'card'],
        ], 200)]);
    }

    // --- Subscription activated -------------------------------------------

    public function test_activation_notification_contains_business_plan_billing_period_amount_and_dates(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id, 'name' => 'Acme Traders']);

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'success', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertSentTo($business->owner, SubscriptionActivated::class, function ($notification) {
            $mail = $notification->toMail($notification);
            $rendered = implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

            $this->assertStringContainsString('Acme Traders', $rendered);
            $this->assertStringContainsString('Pro', $rendered);
            $this->assertStringContainsString('Monthly', $rendered);
            $this->assertStringContainsString('₦15,000.00', $rendered);
            $this->assertStringContainsString(now()->format('d M Y'), $rendered);

            return true;
        });
    }

    public function test_a_failed_payment_does_not_send_an_activation_notification(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'failed', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertNotSentTo($business->owner, SubscriptionActivated::class);
    }

    // --- Payment failed -----------------------------------------------

    public function test_payment_failed_notification_contains_business_plan_billing_period_amount_and_reference(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id, 'name' => 'Zenith Shop']);

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'failed', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertSentTo($business->owner, PaymentFailed::class, function ($notification) use ($transaction) {
            $mail = $notification->toMail($notification);
            $rendered = implode(' ', $mail->introLines);

            $this->assertStringContainsString('Zenith Shop', $rendered);
            $this->assertStringContainsString('Pro', $rendered);
            $this->assertStringContainsString('Monthly', $rendered);
            $this->assertStringContainsString('₦15,000.00', $rendered);
            $this->assertStringContainsString($transaction->reference, $rendered);
            $this->assertStringNotContainsString('succeeded', strtolower($rendered));

            return true;
        });
    }

    public function test_a_successful_payment_does_not_send_a_payment_failed_notification(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'success', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertNotSentTo($business->owner, PaymentFailed::class);
    }

    public function test_a_failure_notification_never_accompanies_an_activated_subscription(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->fakeInit();
        $this->actingAs($business->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'failed', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        $this->assertSame($standard->id, $business->fresh()->plan_id);
    }

    // --- Expiry reminder: window, exclusions, and dedup --------------------

    public function test_a_subscription_within_the_configured_window_receives_a_reminder(): void
    {
        config(['subscriptions.expiry_reminder_days' => 10]);
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(8)]);

        $this->artisan('subscriptions:expire');

        Notification::assertSentTo($business->owner, SubscriptionExpiringSoon::class);
    }

    public function test_a_subscription_outside_the_configured_window_does_not_receive_a_reminder(): void
    {
        config(['subscriptions.expiry_reminder_days' => 3]);
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(20)]);

        $this->artisan('subscriptions:expire');

        Notification::assertNotSentTo($business->owner, SubscriptionExpiringSoon::class);
        $this->assertNull($business->currentSubscription->fresh()->expiry_reminder_sent_at);
    }

    public function test_an_already_expired_subscription_does_not_receive_a_reminder(): void
    {
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['status' => Subscription::STATUS_EXPIRED, 'expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire');

        Notification::assertNotSentTo($business->owner, SubscriptionExpiringSoon::class);
    }

    public function test_a_suspended_subscription_does_not_receive_a_reminder(): void
    {
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['status' => Subscription::STATUS_SUSPENDED, 'expires_at' => now()->addDays(2)]);

        $this->artisan('subscriptions:expire');

        Notification::assertNotSentTo($business->owner, SubscriptionExpiringSoon::class);
    }

    public function test_expiry_reminder_sent_at_is_set_after_a_reminder_is_sent(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->addDays(2)]);
        $this->assertNull($business->currentSubscription->expiry_reminder_sent_at);

        $this->artisan('subscriptions:expire');

        $this->assertNotNull($business->currentSubscription->fresh()->expiry_reminder_sent_at);
    }

    // --- Expired: single-send guarantee, no reactivation -----------------

    public function test_running_the_expiration_command_again_sends_no_second_expired_notification(): void
    {
        Notification::fake();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire');
        $this->artisan('subscriptions:expire');
        $this->artisan('subscriptions:expire');

        Notification::assertSentToTimes($business->owner, SubscriptionExpired::class, 1);
    }

    public function test_the_expired_notification_never_reactivates_the_subscription(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire');

        $this->assertSame(Subscription::STATUS_EXPIRED, $business->currentSubscription->fresh()->status);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_expired_notification_content_names_the_business_and_previous_plan(): void
    {
        Notification::fake();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $premium->id, 'name' => 'Lagos Mart']);
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire');

        Notification::assertSentTo($business->owner, SubscriptionExpired::class, function ($notification) {
            $mail = $notification->toMail($notification);
            $rendered = implode(' ', $mail->introLines);

            $this->assertStringContainsString('Lagos Mart', $rendered);
            $this->assertStringContainsString('Premium', $rendered);
            $this->assertStringContainsString('ended', strtolower($rendered));

            return true;
        });
    }

    // --- Tenant isolation ---------------------------------------------

    public function test_business_a_never_receives_business_bs_activation_notification(): void
    {
        Notification::fake();
        $a = Business::factory()->create();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $b = Business::factory()->create(['plan_id' => $standard->id]);

        $this->fakeInit();
        $this->actingAs($b->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $b->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'success', $transaction->amount);
        $this->actingAs($b->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertSentTo($b->owner, SubscriptionActivated::class);
        Notification::assertNotSentTo($a->owner, SubscriptionActivated::class);
    }

    public function test_payment_failure_for_business_a_cannot_notify_business_b(): void
    {
        Notification::fake();
        $b = Business::factory()->create();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $a = Business::factory()->create(['plan_id' => $standard->id]);

        $this->fakeInit();
        $this->actingAs($a->owner)->post('/subscription/pay', ['plan_id' => $pro->id]);
        $transaction = PaymentTransaction::where('business_id', $a->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'failed', $transaction->amount);
        $this->actingAs($a->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertSentTo($a->owner, PaymentFailed::class);
        Notification::assertNotSentTo($b->owner, PaymentFailed::class);
    }

    public function test_subscription_expiry_for_business_a_cannot_notify_business_b(): void
    {
        Notification::fake();
        $a = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::PREMIUM)->firstOrFail()->id]);
        $a->currentSubscription->update(['expires_at' => now()->subDay()]);
        $b->currentSubscription->update(['expires_at' => now()->addMonth()]);

        $this->artisan('subscriptions:expire');

        Notification::assertSentTo($a->owner, SubscriptionExpired::class);
        Notification::assertNotSentTo($b->owner, SubscriptionExpired::class);
    }

    public function test_the_recipient_is_derived_from_the_business_owner_relationship_not_a_submitted_email(): void
    {
        Notification::fake();
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $imposter = User::factory()->create(['email' => 'attacker@example.com']);

        $this->fakeInit();
        // Attempt to smuggle an alternate notification recipient email.
        $this->actingAs($business->owner)->post('/subscription/pay', [
            'plan_id' => $pro->id,
            'email' => 'attacker@example.com',
            'notify' => 'attacker@example.com',
        ]);
        $transaction = PaymentTransaction::where('business_id', $business->id)->latest()->first();
        $this->fakeVerify($transaction->reference, 'success', $transaction->amount);
        $this->actingAs($business->owner)->get('/subscription/callback?reference='.$transaction->reference);

        Notification::assertSentTo($business->owner, SubscriptionActivated::class);
        Notification::assertNotSentTo($imposter, SubscriptionActivated::class);
    }

    public function test_admin_assigning_a_plan_directly_does_not_notify_the_wrong_business(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);
        $a = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $this->actingAs($admin)->patch("/admin/businesses/{$a->handle}/plan", ['plan_id' => $premium->id]);

        // Admin-assigned plans are a deliberate exception: this phase's
        // SubscriptionActivated is scoped to payment-verified activation
        // only (see PaymentService), so neither business is notified here
        // — proving, at minimum, that B is never notified for A's change.
        Notification::assertNotSentTo($b->owner, SubscriptionActivated::class);
    }
}
