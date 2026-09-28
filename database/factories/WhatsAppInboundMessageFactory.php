<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppInboundMessage>
 */
class WhatsAppInboundMessageFactory extends Factory
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
            'whatsapp_message_id' => 'wamid.'.Str::random(20),
            'message_type' => 'text',
            'received_at' => now(),
        ];
    }
}
