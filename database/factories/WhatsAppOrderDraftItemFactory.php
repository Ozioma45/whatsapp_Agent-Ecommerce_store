<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\WhatsAppOrderDraft;
use App\Models\WhatsAppOrderDraftItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppOrderDraftItem>
 */
class WhatsAppOrderDraftItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draft_id' => WhatsAppOrderDraft::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
        ];
    }
}
