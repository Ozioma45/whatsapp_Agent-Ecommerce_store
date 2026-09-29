<?php

namespace Database\Factories;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppOrderDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppOrderDraft>
 */
class WhatsAppOrderDraftFactory extends Factory
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
            'customer_name' => null,
            'customer_phone' => null,
            'pending_action' => null,
            'confirmation_snapshot' => null,
        ];
    }
}
