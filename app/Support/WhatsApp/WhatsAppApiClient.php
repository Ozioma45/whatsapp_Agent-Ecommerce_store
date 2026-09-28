<?php

namespace App\Support\WhatsApp;

use App\Models\WhatsAppIntegrationSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place in the app that talks to the WhatsApp Cloud API's Graph
 * endpoint. Kept deliberately narrow: one method, text messages only.
 * Never logs or returns the access token it authenticates with, and never
 * lets a request-level exception bubble up with it attached.
 */
class WhatsAppApiClient
{
    /**
     * Send one plain-text message using the given integration's own
     * phone number id and access token.
     */
    public function sendTextMessage(WhatsAppIntegrationSetting $integration, string $to, string $text): WhatsAppSendResult
    {
        $version = config('services.whatsapp.api_version', 'v20.0');
        $url = "https://graph.facebook.com/{$version}/{$integration->phone_number_id}/messages";

        try {
            $response = Http::withToken($integration->access_token)
                ->timeout(10)
                ->connectTimeout(5)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $text,
                    ],
                ]);
        } catch (ConnectionException) {
            Log::warning('whatsapp.send.network_error', ['business_id' => $integration->business_id]);

            return WhatsAppSendResult::failure(WhatsAppSendResult::NETWORK_ERROR, 'Could not reach WhatsApp. Please try again.');
        }

        if ($response->successful()) {
            $messageId = (string) $response->json('messages.0.id');

            Log::info('whatsapp.send.accepted', ['business_id' => $integration->business_id]);

            return WhatsAppSendResult::accepted($messageId);
        }

        return $this->failureFromResponse($integration, $response);
    }

    private function failureFromResponse(WhatsAppIntegrationSetting $integration, Response $response): WhatsAppSendResult
    {
        $status = match (true) {
            $response->status() === 401, $response->status() === 403 => WhatsAppSendResult::AUTH_FAILURE,
            $response->status() === 429 => WhatsAppSendResult::RATE_LIMITED,
            $response->status() === 400 => WhatsAppSendResult::INVALID_RECIPIENT,
            default => WhatsAppSendResult::UNEXPECTED_ERROR,
        };

        // The response body may echo request details back (Meta's error
        // payloads do not include the token, but nothing here risks it
        // regardless — only our own status/http-code are logged).
        Log::warning('whatsapp.send.failed', [
            'business_id' => $integration->business_id,
            'outcome' => $status,
            'http_status' => $response->status(),
        ]);

        return WhatsAppSendResult::failure($status, $this->messageFor($status));
    }

    private function messageFor(string $status): string
    {
        return match ($status) {
            WhatsAppSendResult::AUTH_FAILURE => 'WhatsApp rejected this business\'s credentials. The integration may need to be reconnected.',
            WhatsAppSendResult::RATE_LIMITED => 'WhatsApp is rate-limiting this business right now. Please try again shortly.',
            WhatsAppSendResult::INVALID_RECIPIENT => 'WhatsApp rejected the request — check the recipient number and try again.',
            default => 'WhatsApp returned an unexpected error. Please try again later.',
        };
    }
}
