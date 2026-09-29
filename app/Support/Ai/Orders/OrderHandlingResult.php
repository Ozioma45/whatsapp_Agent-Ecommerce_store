<?php

namespace App\Support\Ai\Orders;

use App\Models\Order;

/**
 * The outcome of offering one customer message to OrderConversationHandler.
 * A null `reply` means the message had nothing to do with the order flow,
 * and the caller (ConversationEngine) should fall back to the general AI
 * provider instead.
 */
final readonly class OrderHandlingResult
{
    private function __construct(
        public ?string $reply,
        public ?Order $order = null,
        public bool $simulatedOrder = false,
    ) {}

    public static function none(): self
    {
        return new self(null);
    }

    public static function reply(string $reply, ?Order $order = null, bool $simulatedOrder = false): self
    {
        return new self($reply, $order, $simulatedOrder);
    }
}
