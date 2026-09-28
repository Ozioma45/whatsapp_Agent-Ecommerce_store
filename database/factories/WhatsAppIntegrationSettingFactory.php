<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\WhatsAppIntegrationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppIntegrationSetting>
 */
class WhatsAppIntegrationSettingFactory extends Factory
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
            'phone_number_id' => fake()->unique()->numerify('##########'),
            'whatsapp_business_account_id' => fake()->numerify('##########'),
            'access_token' => Str::random(40),
            'webhook_verify_token' => Str::random(24),
            'status' => WhatsAppIntegrationSetting::STATUS_CONNECTED,
        ];
    }

    /**
     * A business that has not (or no longer) connected WhatsApp.
     */
    public function disconnected(): static
    {
        return $this->state(fn () => ['status' => WhatsAppIntegrationSetting::STATUS_DISCONNECTED]);
    }
}
