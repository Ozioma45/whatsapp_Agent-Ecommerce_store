<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_a_guest_cannot_view_the_subscription_page(): void
    {
        $this->get('/subscription')->assertRedirect('/login');
    }

    public function test_an_owner_can_view_their_own_subscription(): void
    {
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $pro->id]);

        $this->actingAs($business->owner)->get('/subscription')
            ->assertOk()
            ->assertSee('Pro')
            ->assertSee('Active');
    }

    public function test_an_owner_never_sees_another_businesss_subscription_status_or_request(): void
    {
        $a = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $b = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $b->currentSubscription->update(['status' => Subscription::STATUS_SUSPENDED]);
        app(SubscriptionService::class)->requestPlanChange($b, Plan::where('slug', Plan::PREMIUM)->firstOrFail());

        $response = $this->actingAs($a->owner)->get('/subscription');

        $response->assertOk();
        $response->assertDontSee('Suspended');
        $response->assertDontSee('Pending plan change');
    }

    public function test_plan_features_and_prices_are_displayed(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);

        $response = $this->actingAs($business->owner)->get('/subscription');

        $response->assertOk();
        foreach (Plan::where('is_active', true)->get() as $plan) {
            $response->assertSee($plan->name);
            $response->assertSee(number_format($plan->price, 2));
        }
    }

    public function test_a_pending_request_is_displayed(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        app(SubscriptionService::class)->requestPlanChange($business, $pro);

        $this->actingAs($business->owner)->get('/subscription')
            ->assertOk()
            ->assertSee('Pending plan change');
    }

    public function test_an_expired_subscription_status_is_visible(): void
    {
        $business = Business::factory()->create();
        $business->currentSubscription->update(['expires_at' => now()->subDay()]);

        $this->actingAs($business->owner)->get('/subscription')
            ->assertOk()
            ->assertSee('Expired');
    }

    public function test_a_suspended_subscription_status_is_visible(): void
    {
        $business = Business::factory()->create();
        $business->currentSubscription->update(['status' => Subscription::STATUS_SUSPENDED]);

        $this->actingAs($business->owner)->get('/subscription')
            ->assertOk()
            ->assertSee('Suspended');
    }

    public function test_an_admin_with_no_business_of_their_own_gets_a_404(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/subscription')->assertNotFound();
    }
}
