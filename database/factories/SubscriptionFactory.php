<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'plan_id' => Plan::factory(),
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->toDateString(),
            'expires_at' => null,
        ];
    }

    /**
     * An owner-submitted request awaiting admin review.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => Subscription::STATUS_PENDING,
            'starts_at' => null,
            'requested_at' => now(),
        ]);
    }

    /**
     * An "active" row whose expiry date has already passed.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->subDay()->toDateString(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Subscription::STATUS_SUSPENDED]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => Subscription::STATUS_CANCELLED]);
    }
}
