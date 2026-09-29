<?php

namespace App\Support\Ai;

/**
 * The outcome of one conversation-simulator run. Unlike ConversationOutcome
 * (used for real customer messages), this is shown to the business's own,
 * already-authenticated owner, so outcomes are reported precisely rather
 * than collapsed for privacy.
 */
final readonly class ConversationSimulationResult
{
    public const NOT_ELIGIBLE = 'not_eligible';

    public const DISABLED = 'disabled';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const EMPTY_MESSAGE = 'empty_message';

    private function __construct(
        public bool $successful,
        public string $status,
        public ?string $customerMessage,
        public ?string $reply,
        public array $productsUsed,
        public array $draft = [],
        public bool $simulatedOrderCreated = false,
    ) {}

    /**
     * @param  array<int, array{name: string, description: ?string, price: string, available: bool, category: ?string}>  $productsUsed
     * @param  array{lines: array<int, array{name: string, quantity: int, unit_price: string, subtotal: string}>, total: string}  $draft
     */
    public static function ok(string $customerMessage, string $reply, array $productsUsed, array $draft = [], bool $simulatedOrderCreated = false): self
    {
        return new self(true, 'ok', $customerMessage, $reply, $productsUsed, $draft, $simulatedOrderCreated);
    }

    public static function failed(string $status): self
    {
        return new self(false, $status, null, null, []);
    }
}
