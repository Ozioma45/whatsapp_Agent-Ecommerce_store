<?php

namespace App\Support\WhatsApp;

use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppIntegrationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Verifies and processes one incoming WhatsApp webhook delivery.
 *
 * This is foundation only: it identifies which business a message belongs
 * to and records that it arrived, de-duplicated by WhatsApp's own message
 * id. It never generates a reply, sends a message, or creates an order —
 * those are handled by later phases, on top of this identification step.
 */
class WhatsAppWebhookProcessor
{
    /**
     * Verify the request against Meta's X-Hub-Signature-256 header, using
     * the platform's configured app secret. The verification token used
     * for the GET challenge is a separate, weaker secret and is never
     * treated as a substitute for this signature check.
     */
    public function hasValidSignature(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function process(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $this->processChange((array) ($change['value'] ?? []));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function processChange(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            return;
        }

        // The business is resolved strictly from our own stored integration
        // settings, keyed by the platform-assigned phone number id — never
        // from anything the payload itself claims about "whose" message it is.
        $integration = WhatsAppIntegrationSetting::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('status', WhatsAppIntegrationSetting::STATUS_CONNECTED)
            ->first();

        if (! $integration) {
            Log::info('whatsapp.webhook.unknown_phone_number');

            return;
        }

        foreach ((array) ($value['messages'] ?? []) as $rawMessage) {
            $this->processMessage($integration, (array) $rawMessage);
        }
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     */
    private function processMessage(WhatsAppIntegrationSetting $integration, array $rawMessage): void
    {
        $message = WhatsAppMessageParser::parse($rawMessage);

        if (! $message) {
            Log::info('whatsapp.webhook.unsupported_message_type', ['business_id' => $integration->business_id]);

            return;
        }

        $inbound = WhatsAppInboundMessage::firstOrCreate(
            ['whatsapp_message_id' => $message->messageId],
            [
                'business_id' => $integration->business_id,
                'message_type' => $message->type,
                'received_at' => $message->timestamp,
            ]
        );

        if (! $inbound->wasRecentlyCreated) {
            Log::info('whatsapp.webhook.duplicate_message', [
                'business_id' => $integration->business_id,
                'message_id' => $message->messageId,
            ]);

            return;
        }

        Log::info('whatsapp.webhook.message_received', [
            'business_id' => $integration->business_id,
            'message_id' => $message->messageId,
        ]);
    }
}
