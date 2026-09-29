<?php

namespace App\Support\Ai;

use App\Models\Order;
use App\Support\WhatsApp\WhatsAppSendResult;

/**
 * The outcome of one real, incoming-webhook-triggered conversation turn.
 *
 * NOT_AVAILABLE deliberately covers both "not entitled" and "assistant
 * disabled" with no way to tell them apart from the outside — mirroring
 * AiAssistantService::isAvailableFor(), so a message sender can never
 * learn which plan a business is on or whether it merely switched the
 * assistant off.
 */
final readonly class ConversationOutcome
{
    public const RESPONDED = 'responded';

    public const NOT_AVAILABLE = 'not_available';

    public const ALREADY_PROCESSED = 'already_processed';

    public const UNSUPPORTED_MESSAGE = 'unsupported_message';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const EMPTY_RESPONSE = 'empty_response';

    public const SEND_FAILED = 'send_failed';

    private function __construct(
        public bool $successful,
        public string $status,
        public ?string $replyText,
        public ?WhatsAppSendResult $sendResult,
        public ?Order $order = null,
    ) {}

    /**
     * $order is set only when this turn resulted in a real, pending Order
     * being created (App\Support\OrderCreationService) — never anything
     * the AI provider produced or touched directly.
     */
    public static function responded(string $replyText, WhatsAppSendResult $sendResult, ?Order $order = null): self
    {
        return new self(true, self::RESPONDED, $replyText, $sendResult, $order);
    }

    public static function failed(string $status, ?WhatsAppSendResult $sendResult = null): self
    {
        return new self(false, $status, null, $sendResult);
    }
}
