<?php

namespace App\Http\Controllers;

use App\Support\WhatsApp\WhatsAppWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single, platform-wide WhatsApp Business Platform webhook endpoint.
 * One business is identified per message via its stored phone number id
 * (see WhatsAppWebhookProcessor) — never via anything supplied directly in
 * the request. This controller stays thin: verification and dispatch only.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppWebhookProcessor $processor) {}

    /**
     * Meta's webhook verification challenge (GET). Only responds with the
     * challenge when the configured verification token matches; otherwise
     * rejects the attempt without revealing why.
     */
    public function verify(Request $request): Response
    {
        $expected = config('services.whatsapp.verify_token');

        if (
            is_string($expected) && $expected !== ''
            && $request->query('hub_mode') === 'subscribe'
            && is_string($request->query('hub_verify_token'))
            && hash_equals($expected, $request->query('hub_verify_token'))
        ) {
            return response((string) $request->query('hub_challenge'));
        }

        return response('', 403);
    }

    /**
     * Incoming webhook delivery (POST). Always responds quickly and never
     * leaks internal error detail to the caller — Meta only ever sees a
     * plain acknowledgement or a rejection.
     */
    public function handle(Request $request): Response
    {
        if (! $this->processor->hasValidSignature($request)) {
            Log::warning('whatsapp.webhook.invalid_signature');

            return response('', 403);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! isset($payload['object'], $payload['entry']) || ! is_array($payload['entry'])) {
            Log::warning('whatsapp.webhook.malformed_payload');

            return response('', 400);
        }

        try {
            $this->processor->process($payload);
        } catch (Throwable $e) {
            report($e);
        }

        return response('EVENT_RECEIVED');
    }
}
