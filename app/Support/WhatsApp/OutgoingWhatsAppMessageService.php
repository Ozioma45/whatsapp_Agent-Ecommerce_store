<?php

namespace App\Support\WhatsApp;

use App\Models\Business;
use App\Models\WhatsAppIntegrationSetting;

/**
 * Sends one outgoing WhatsApp text message on behalf of a single business.
 *
 * Credentials are resolved only from the given business's own
 * relationship — never from an id or token supplied by a caller — so this
 * can never be tricked into sending under another business's identity.
 * This does not generate the message text; that is the caller's job (a
 * future phase, a test tool, or eventually an AI-composed reply going
 * through its own separate review).
 */
class OutgoingWhatsAppMessageService
{
    /**
     * WhatsApp's documented limit for a text message body.
     */
    private const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(private readonly WhatsAppApiClient $client) {}

    public function send(Business $business, string $to, string $text): WhatsAppSendResult
    {
        $integration = $business->whatsAppIntegrationSetting;

        if (! $integration || $integration->status !== WhatsAppIntegrationSetting::STATUS_CONNECTED) {
            return WhatsAppSendResult::failure(WhatsAppSendResult::NOT_CONNECTED, 'This business does not have a connected WhatsApp integration.');
        }

        if (blank($integration->phone_number_id) || blank($integration->access_token)) {
            return WhatsAppSendResult::failure(WhatsAppSendResult::NOT_CONNECTED, 'This business\'s WhatsApp integration is missing required credentials.');
        }

        $to = trim($to);

        if ($to === '' || ! preg_match('/^\+?[1-9]\d{6,14}$/', $to)) {
            return WhatsAppSendResult::failure(WhatsAppSendResult::VALIDATION_FAILED, 'The recipient WhatsApp number is not valid.');
        }

        $text = trim($text);

        if ($text === '') {
            return WhatsAppSendResult::failure(WhatsAppSendResult::VALIDATION_FAILED, 'The message cannot be empty.');
        }

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            return WhatsAppSendResult::failure(
                WhatsAppSendResult::VALIDATION_FAILED,
                'The message exceeds WhatsApp\'s '.self::MAX_MESSAGE_LENGTH.'-character limit.'
            );
        }

        // Meta's API expects the recipient in E.164 digits, no leading "+".
        return $this->client->sendTextMessage($integration, ltrim($to, '+'), $text);
    }
}
