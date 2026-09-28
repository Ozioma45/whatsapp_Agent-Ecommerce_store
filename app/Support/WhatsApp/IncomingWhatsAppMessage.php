<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Carbon;

/**
 * One parsed, supported inbound WhatsApp message. Carries only what this
 * phase needs to identify and de-duplicate a message — no reply is
 * generated from it and it is never persisted with its text body.
 */
final readonly class IncomingWhatsAppMessage
{
    public function __construct(
        public string $messageId,
        public string $from,
        public Carbon $timestamp,
        public string $type,
        public ?string $text,
    ) {}
}
