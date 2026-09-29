<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 500);

        return [
            'business_id' => Business::factory(),
            'order_number' => Order::generateOrderNumber(),
            'customer_name' => fake()->optional()->name(),
            'customer_phone' => fake()->optional()->numerify('080########'),
            'status' => Order::STATUS_PENDING,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'source' => Order::SOURCE_STOREFRONT,
        ];
    }

    /**
     * An order placed through the WhatsApp AI assistant rather than the
     * storefront cart.
     */
    public function whatsappAi(): static
    {
        return $this->state(fn () => ['source' => Order::SOURCE_WHATSAPP_AI]);
    }
}
