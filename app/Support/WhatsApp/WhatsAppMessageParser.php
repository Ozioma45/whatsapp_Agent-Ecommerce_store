<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Carbon;

/**
 * Extracts a single supported message from a raw WhatsApp webhook message
 * entry. Only text messages are supported in this phase; anything else is
 * safely ignored (returns null) rather than raising an error.
 */
class WhatsAppMessageParser
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_TYPES = ['text'];

    /**
     * @param  array<string, mixed>  $rawMessage
     */
    public static function parse(array $rawMessage): ?IncomingWhatsAppMessage
    {
        $type = $rawMessage['type'] ?? null;
        $id = $rawMessage['id'] ?? null;
        $from = $rawMessage['from'] ?? null;
        $timestamp = $rawMessage['timestamp'] ?? null;

        if (! is_string($type) || ! in_array($type, self::SUPPORTED_TYPES, true)) {
            return null;
        }

        if (! is_string($id) || $id === '' || ! is_string($from) || $from === '' || ! is_numeric($timestamp)) {
            return null;
        }

        return new IncomingWhatsAppMessage(
            messageId: $id,
            from: $from,
            timestamp: Carbon::createFromTimestamp((int) $timestamp),
            type: $type,
            text: is_string($rawMessage['text']['body'] ?? null) ? $rawMessage['text']['body'] : null,
        );
    }
}
