<?php

namespace App\Support\Ai\Orders;

use App\Models\Business;

/**
 * The conversation simulator's draft state, kept entirely in the session
 * of the business owner running the simulation — never in the database.
 * This is what lets the simulator exercise the full, real order flow
 * (OrderConversationHandler, unmodified) without ever creating a
 * WhatsAppConversation, a WhatsAppOrderDraft, or (via the $simulate flag
 * passed separately to the handler) a real Order.
 */
class SessionOrderDraftStore implements OrderDraftStore
{
    public function __construct(private readonly Business $business) {}

    public function get(): array
    {
        return session($this->key(), DatabaseOrderDraftStore::emptyState());
    }

    public function save(array $state): void
    {
        session([$this->key() => $state]);
    }

    public function delete(): void
    {
        session()->forget($this->key());
    }

    private function key(): string
    {
        return "ai_simulation_draft.{$this->business->id}";
    }
}
