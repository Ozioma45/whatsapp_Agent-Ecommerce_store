<?php

namespace App\Support\Ai;

use App\Models\Business;
use App\Models\Feature;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use App\Support\Ai\Orders\DatabaseOrderDraftStore;
use App\Support\Ai\Orders\OrderConversationHandler;
use App\Support\Ai\Orders\SessionOrderDraftStore;
use App\Support\WhatsApp\IncomingWhatsAppMessage;
use App\Support\WhatsApp\OutgoingWhatsAppMessageService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connects an already-identified incoming WhatsApp message to the AI
 * assistant and, when it generates a reply, to the outgoing WhatsApp
 * service — the one place all of Phases 9A–9C meet.
 *
 * This class does not resolve the business, verify the webhook, or parse
 * the raw message; those stay exactly where they already lived
 * (WhatsAppWebhookProcessor / WhatsAppMessageParser). It only ever
 * receives a business that has already been resolved from a verified,
 * connected integration — it never looks one up itself.
 */
class ConversationEngine
{
    /**
     * How many prior messages of one conversation to give the AI as
     * context. Deliberately small: this is a lightweight transcript, not
     * a full memory system.
     */
    private const HISTORY_LIMIT = 10;

    public function __construct(
        private readonly AiAssistantService $assistant,
        private readonly AiProviderInterface $provider,
        private readonly OutgoingWhatsAppMessageService $whatsapp,
        private readonly DeterministicFakeAiProvider $fakeProvider,
        private readonly OrderConversationHandler $orderHandler,
    ) {}

    /**
     * The real flow for one incoming customer message. Safe to call even
     * if the caller's own idempotency check somehow let a duplicate
     * through — this re-checks against the conversation's own history.
     */
    public function handleIncomingMessage(Business $business, IncomingWhatsAppMessage $message): ConversationOutcome
    {
        if (! $this->assistant->isAvailableFor($business)) {
            return ConversationOutcome::failed(ConversationOutcome::NOT_AVAILABLE);
        }

        if ($message->text === null || trim($message->text) === '') {
            return ConversationOutcome::failed(ConversationOutcome::UNSUPPORTED_MESSAGE);
        }

        $conversation = $business->whatsAppConversations()->firstOrCreate([
            'customer_whatsapp_number' => $message->from,
        ]);

        if ($this->alreadyProcessed($conversation, $message->messageId)) {
            Log::info('whatsapp.conversation.duplicate', ['business_id' => $business->id]);

            return ConversationOutcome::failed(ConversationOutcome::ALREADY_PROCESSED);
        }

        $history = $this->recentHistory($conversation);

        $conversation->messages()->create([
            'direction' => WhatsAppConversationMessage::DIRECTION_INBOUND,
            'message_type' => $message->type,
            'content' => $message->text,
            'whatsapp_message_id' => $message->messageId,
            'occurred_at' => $message->timestamp,
        ]);

        $context = $this->assistant->buildContext($business, $history);

        // The order flow is tried first and is entirely deterministic
        // (OrderIntentParser) — no AI provider is involved in it at all.
        // A null reply here means the message wasn't order-related, so the
        // general AI provider answers it instead, exactly as before.
        $availableProducts = $business->products()->where('is_available', true)->get()->keyBy('id');
        $orderStore = new DatabaseOrderDraftStore($conversation);
        $orderResult = $this->orderHandler->handle($business, $orderStore, $availableProducts, $message->text, $message->from, simulate: false);

        $createdOrder = $orderResult->order;

        if ($orderResult->reply !== null) {
            $reply = $orderResult->reply;
        } else {
            try {
                $reply = $this->provider->generateResponse($context, $message->text);
            } catch (Throwable $e) {
                report($e);
                Log::warning('whatsapp.conversation.provider_error', ['business_id' => $business->id]);

                return ConversationOutcome::failed(ConversationOutcome::PROVIDER_UNAVAILABLE);
            }
        }

        if (trim($reply) === '') {
            Log::warning('whatsapp.conversation.empty_response', ['business_id' => $business->id]);

            return ConversationOutcome::failed(ConversationOutcome::EMPTY_RESPONSE);
        }

        // Meta accepting the request is not the same as the customer
        // receiving it — see WhatsAppSendResult::accepted().
        $sendResult = $this->whatsapp->send($business, $message->from, $reply);

        if (! $sendResult->successful) {
            Log::warning('whatsapp.conversation.send_failed', [
                'business_id' => $business->id,
                'outcome' => $sendResult->status,
            ]);

            return ConversationOutcome::failed(ConversationOutcome::SEND_FAILED, $sendResult);
        }

        $conversation->messages()->create([
            'direction' => WhatsAppConversationMessage::DIRECTION_OUTBOUND,
            'message_type' => 'text',
            'content' => $reply,
            'whatsapp_message_id' => $sendResult->messageId,
            'occurred_at' => now(),
        ]);

        Log::info('whatsapp.conversation.responded', ['business_id' => $business->id]);

        return ConversationOutcome::responded($reply, $sendResult, $createdOrder);
    }

    /**
     * A read-only preview for the business's own, already-authenticated
     * owner. Always uses the deterministic fake provider — never a real
     * (and possibly paid) one — and never sends a real WhatsApp message,
     * touches conversation history, or persists anything.
     */
    public function simulate(Business $business, string $customerMessage): ConversationSimulationResult
    {
        if (! $business->hasFeature(Feature::AI_ASSISTANT)) {
            return ConversationSimulationResult::failed(ConversationSimulationResult::NOT_ELIGIBLE);
        }

        if (! (bool) $business->aiAssistantSettings?->enabled) {
            return ConversationSimulationResult::failed(ConversationSimulationResult::DISABLED);
        }

        $customerMessage = trim($customerMessage);

        if ($customerMessage === '') {
            return ConversationSimulationResult::failed(ConversationSimulationResult::EMPTY_MESSAGE);
        }

        $availableProducts = $business->products()->where('is_available', true)->get()->keyBy('id');
        $store = new SessionOrderDraftStore($business);

        $orderResult = $this->orderHandler->handle($business, $store, $availableProducts, $customerMessage, channelPhone: '', simulate: true);
        $draftView = $this->orderHandler->currentDraftView($store, $availableProducts);

        if ($orderResult->reply !== null) {
            return ConversationSimulationResult::ok($customerMessage, $orderResult->reply, [], $draftView, $orderResult->simulatedOrder);
        }

        $context = $this->assistant->buildContext($business);

        try {
            $reply = $this->fakeProvider->generateResponse($context, $customerMessage);
        } catch (Throwable $e) {
            report($e);

            return ConversationSimulationResult::failed(ConversationSimulationResult::PROVIDER_UNAVAILABLE);
        }

        return ConversationSimulationResult::ok($customerMessage, $reply, $context->products, $draftView);
    }

    /**
     * Discards the current business owner's simulated order draft, so the
     * simulator can be reset to a clean conversation.
     */
    public function resetSimulation(Business $business): void
    {
        (new SessionOrderDraftStore($business))->delete();
    }

    private function alreadyProcessed(WhatsAppConversation $conversation, string $whatsappMessageId): bool
    {
        return $conversation->messages()
            ->where('direction', WhatsAppConversationMessage::DIRECTION_INBOUND)
            ->where('whatsapp_message_id', $whatsappMessageId)
            ->exists();
    }

    /**
     * @return array<int, array{role: string, text: string}>
     */
    private function recentHistory(WhatsAppConversation $conversation): array
    {
        return $conversation->messages()
            ->orderByDesc('occurred_at')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (WhatsAppConversationMessage $message) => [
                'role' => $message->direction === WhatsAppConversationMessage::DIRECTION_INBOUND ? 'customer' : 'assistant',
                'text' => (string) $message->content,
            ])
            ->values()
            ->all();
    }
}
