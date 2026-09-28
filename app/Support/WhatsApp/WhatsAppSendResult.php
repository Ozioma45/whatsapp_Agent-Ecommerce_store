<?php

namespace App\Support\WhatsApp;

/**
 * The outcome of one attempt to send an outgoing WhatsApp message.
 *
 * `accepted()` means only that Meta accepted the request for delivery —
 * never that the message actually reached the recipient's device. Real
 * delivery/read confirmation is a separate status webhook, out of scope
 * for this phase. Deliberately carries no credentials.
 */
final readonly class WhatsAppSendResult
{
    public const ACCEPTED = 'accepted';

    public const AUTH_FAILURE = 'auth_failure';

    public const INVALID_RECIPIENT = 'invalid_recipient';

    public const RATE_LIMITED = 'rate_limited';

    public const NETWORK_ERROR = 'network_error';

    public const UNEXPECTED_ERROR = 'unexpected_error';

    public const VALIDATION_FAILED = 'validation_failed';

    public const NOT_CONNECTED = 'not_connected';

    private function __construct(
        public bool $successful,
        public string $status,
        public ?string $messageId,
        public ?string $error,
    ) {}

    /**
     * Meta accepted the message and assigned it a WhatsApp message id.
     */
    public static function accepted(string $messageId): self
    {
        return new self(true, self::ACCEPTED, $messageId, null);
    }

    /**
     * Any failure outcome, with a safe (never credential-bearing) message.
     */
    public static function failure(string $status, string $error): self
    {
        return new self(false, $status, null, $error);
    }
}
