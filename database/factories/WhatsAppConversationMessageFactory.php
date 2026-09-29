<?php

namespace Database\Factories;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppConversationMessage>
 */
class WhatsAppConversationMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => WhatsAppConversation::factory(),
            'direction' => WhatsAppConversationMessage::DIRECTION_INBOUND,
            'message_type' => 'text',
            'content' => fake()->sentence(),
            'whatsapp_message_id' => 'wamid.'.Str::random(20),
            'occurred_at' => now(),
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn () => ['direction' => WhatsAppConversationMessage::DIRECTION_OUTBOUND]);
    }
}
