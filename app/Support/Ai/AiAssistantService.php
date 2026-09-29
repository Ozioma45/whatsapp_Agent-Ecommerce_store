<?php

namespace App\Support\Ai;

use App\Models\AiAssistantSetting;
use App\Models\Business;
use App\Models\Feature;

/**
 * The assistant's entry point. This phase only establishes the structure:
 * gating, context building, and the provider hand-off. No WhatsApp
 * connection, no external AI call, no conversation storage, and no order
 * creation — a future order must go through the existing server-side
 * cart/order validation, never straight from AI-generated text.
 */
class AiAssistantService
{
    public function __construct(private readonly AiProviderInterface $provider) {}

    /**
     * Both gates must pass: the plan must entitle the business to the
     * assistant (the Phase 8 entitlement system is the only source of
     * truth for that), and the business must have switched it on.
     */
    public function isAvailableFor(Business $business): bool
    {
        return $business->hasFeature(Feature::AI_ASSISTANT)
            && (bool) $business->aiAssistantSettings?->enabled;
    }

    /**
     * Build the context for exactly one business, optionally carrying the
     * recent history of one conversation (already scoped to that business
     * and that customer by the caller — see ConversationEngine).
     *
     * @param  array<int, array{role: string, text: string}>  $conversationHistory
     */
    public function buildContext(Business $business, array $conversationHistory = []): AiContext
    {
        $catalog = new ProductCatalogService($business);
        $settings = $business->aiAssistantSettings;

        return new AiContext(
            businessName: $business->name,
            businessDescription: $business->setting?->description,
            whatsappNumber: $business->setting?->whatsapp_number,
            categories: $catalog->categories()->all(),
            products: $catalog->availableProducts()->all(),
            tone: $settings?->tone ?? AiAssistantSetting::TONE_FRIENDLY,
            welcomeMessage: $settings?->welcome_message,
            businessInstructions: $settings?->business_instructions,
            conversationHistory: $conversationHistory,
        );
    }

    /**
     * Reply to a customer's message on behalf of one business, or return
     * null when the assistant isn't available for it. The null is
     * deliberately the same whether the plan lacks the feature or the
     * business has it switched off, so neither is revealed.
     */
    public function respond(Business $business, string $customerMessage): ?string
    {
        if (! $this->isAvailableFor($business)) {
            return null;
        }

        return $this->provider->generateResponse($this->buildContext($business), $customerMessage);
    }
}
