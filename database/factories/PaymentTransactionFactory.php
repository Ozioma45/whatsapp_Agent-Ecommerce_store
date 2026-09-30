<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
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
            'subscription_id' => null,
            'reference' => PaymentTransaction::generateReference(),
            'amount' => fake()->randomElement([0, 1500000, 4500000]),
            'currency' => 'NGN',
            'status' => PaymentTransaction::STATUS_PENDING,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn () => [
            'status' => PaymentTransaction::STATUS_SUCCESSFUL,
            'verified_at' => now(),
            'paystack_transaction_id' => (string) fake()->unique()->randomNumber(9),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentTransaction::STATUS_FAILED,
            'verified_at' => now(),
            'failure_reason' => 'failed',
        ]);
    }
}
