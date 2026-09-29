<?php

namespace App\Support\Ai\Orders;

use App\Models\WhatsAppConversation;

/**
 * The real, persisted draft for one WhatsApp conversation. Always reached
 * through the conversation's own relation, so a draft can never be read or
 * written for the wrong business or the wrong customer.
 */
class DatabaseOrderDraftStore implements OrderDraftStore
{
    public function __construct(private readonly WhatsAppConversation $conversation) {}

    public function get(): array
    {
        $draft = $this->conversation->orderDraft;

        if (! $draft) {
            return self::emptyState();
        }

        return [
            'items' => $draft->items()->pluck('quantity', 'product_id')->all(),
            'customer_name' => $draft->customer_name,
            'customer_phone' => $draft->customer_phone,
            'pending_action' => $draft->pending_action,
            'confirmation_snapshot' => $draft->confirmation_snapshot,
        ];
    }

    public function save(array $state): void
    {
        $draft = $this->conversation->orderDraft()->firstOrCreate([]);

        $draft->update([
            'customer_name' => $state['customer_name'] ?? null,
            'customer_phone' => $state['customer_phone'] ?? null,
            'pending_action' => $state['pending_action'] ?? null,
            'confirmation_snapshot' => $state['confirmation_snapshot'] ?? null,
        ]);

        $draft->items()->delete();

        foreach ($state['items'] as $productId => $quantity) {
            if ($quantity > 0) {
                $draft->items()->create(['product_id' => $productId, 'quantity' => $quantity]);
            }
        }
    }

    public function delete(): void
    {
        $this->conversation->orderDraft?->delete();
    }

    /**
     * @return array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}
     */
    public static function emptyState(): array
    {
        return [
            'items' => [],
            'customer_name' => null,
            'customer_phone' => null,
            'pending_action' => null,
            'confirmation_snapshot' => null,
        ];
    }
}
