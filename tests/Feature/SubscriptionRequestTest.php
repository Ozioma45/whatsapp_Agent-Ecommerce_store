<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_an_owner_can_submit_a_valid_plan_change_request(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();

        $response = $this->actingAs($business->owner)->post('/subscription/request', ['plan_id' => $pro->id]);

        $response->assertRedirect('/subscription');
        $this->assertDatabaseHas('subscriptions', [
            'business_id' => $business->id,
            'plan_id' => $pro->id,
            'status' => Subscription::STATUS_PENDING,
        ]);
    }

    public function test_an_invalid_plan_id_is_rejected(): void
    {
        $business = Business::factory()->create();

        $response = $this->actingAs($business->owner)->post('/subscription/request', ['plan_id' => 999999]);

        $response->assertSessionHasErrors('plan_id');
    }

    public function test_an_inactive_plan_is_rejected(): void
    {
        $business = Business::factory()->create();
        $inactivePlan = Plan::factory()->create(['is_active' => false]);

        $response = $this->actingAs($business->owner)->post('/subscription/request', ['plan_id' => $inactivePlan->id]);

        $response->assertSessionHasErrors('plan_id');
    }

    public function test_a_duplicate_pending_request_updates_the_existing_one_instead_of_creating_a_second(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $this->actingAs($business->owner)->post('/subscription/request', ['plan_id' => $pro->id]);
        $this->actingAs($business->owner)->post('/subscription/request', ['plan_id' => $premium->id]);

        $this->assertSame(1, $business->subscriptions()->where('status', Subscription::STATUS_PENDING)->count());
        $this->assertSame($premium->id, $business->subscriptions()->where('status', Subscription::STATUS_PENDING)->first()->plan_id);
    }

    public function test_submitting_a_request_does_not_immediately_change_the_plan(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);
        $premium = Plan::where('slug', Plan::PREMIUM)->firstOrFail();

        $this->actingAs($business->owner)->post('/subscription/request', ['plan_id' => $premium->id]);

        $this->assertSame($standard->id, $business->fresh()->plan_id);
        $this->assertFalse($business->fresh()->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_a_guest_cannot_submit_a_request(): void
    {
        $this->post('/subscription/request', ['plan_id' => 1])->assertRedirect('/login');
    }

    public function test_requesting_the_current_plan_is_rejected_without_creating_a_request(): void
    {
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $business = Business::factory()->create(['plan_id' => $standard->id]);

        $this->actingAs($business->owner)->post('/subscription/request', ['plan_id' => $standard->id])
            ->assertRedirect('/subscription');

        $this->assertSame(0, $business->subscriptions()->where('status', Subscription::STATUS_PENDING)->count());
    }

    public function test_a_business_owner_cannot_approve_their_own_request(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', Plan::STANDARD)->firstOrFail()->id]);
        $request = app(SubscriptionService::class)->requestPlanChange($business, Plan::where('slug', Plan::PRO)->firstOrFail());

        $response = $this->actingAs($business->owner)->patch("/admin/subscriptions/{$request->id}/approve");

        $response->assertForbidden();
        $this->assertSame(Subscription::STATUS_PENDING, $request->fresh()->status);
    }
}
