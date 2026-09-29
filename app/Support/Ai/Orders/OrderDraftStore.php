<?php

namespace App\Support\Ai\Orders;

/**
 * Storage for one conversation's order-draft state, abstracted so the same
 * OrderConversationHandler logic can run against a real, persisted draft
 * (DatabaseOrderDraftStore, for real WhatsApp conversations) or an
 * isolated, session-only one (SessionOrderDraftStore, for the simulator)
 * without duplicating any parsing or validation rules between them.
 */
interface OrderDraftStore
{
    /**
     * @return array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}
     */
    public function get(): array;

    /**
     * @param  array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}  $state
     */
    public function save(array $state): void;

    /**
     * Discard the draft entirely (used on cancel, and after an order is
     * created from it).
     */
    public function delete(): void;
}
