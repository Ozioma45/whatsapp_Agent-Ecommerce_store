<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiAssistantSetting;
use App\Models\Feature;
use App\Support\Ai\ConversationEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiAssistantSettingsController extends Controller
{
    /**
     * Show the AI Assistant section for the authenticated user's own
     * business. Businesses whose plan doesn't include the feature only see
     * an informational message — never the form.
     */
    public function edit(Request $request): View
    {
        $business = $request->user()->business()->firstOrFail();
        $eligible = $business->hasFeature(Feature::AI_ASSISTANT);

        return view('settings.ai-assistant', [
            'eligible' => $eligible,
            'settings' => $eligible
                ? $business->aiAssistantSettings()->firstOrCreate([], AiAssistantSetting::defaults())
                : null,
        ]);
    }

    /**
     * Save the AI Assistant settings. The business always comes from the
     * authenticated user (never from the request), and the entitlement is
     * re-checked server-side so a hand-crafted request can't bypass it.
     */
    public function update(Request $request): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();

        abort_unless($business->hasFeature(Feature::AI_ASSISTANT), 403, 'The AI Assistant is not available on your plan.');

        $validated = $request->validate([
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'business_instructions' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', Rule::in(AiAssistantSetting::TONES)],
        ]);

        $business->aiAssistantSettings()->firstOrCreate([], AiAssistantSetting::defaults())->update([
            'enabled' => $request->boolean('enabled'),
            'welcome_message' => $validated['welcome_message'] ?? null,
            'business_instructions' => $validated['business_instructions'] ?? null,
            'tone' => $validated['tone'] ?? null,
        ]);

        return redirect()->route('ai.edit')->with('status', 'AI Assistant settings updated.');
    }

    /**
     * Run one conversation-simulator turn for the authenticated user's own
     * business. This never sends a real WhatsApp message, never touches
     * real conversation history, and never uses a real (or paid) AI
     * provider — see ConversationEngine::simulate().
     */
    public function simulate(Request $request, ConversationEngine $engine): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();

        abort_unless($business->hasFeature(Feature::AI_ASSISTANT), 403, 'The AI Assistant is not available on your plan.');

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $result = $engine->simulate($business, $validated['message']);

        return redirect()->route('ai.edit')->with('simulation', [
            'successful' => $result->successful,
            'status' => $result->status,
            'customer_message' => $result->customerMessage,
            'reply' => $result->reply,
            'products' => $result->productsUsed,
            'draft' => $result->draft,
            'simulated_order_created' => $result->simulatedOrderCreated,
        ]);
    }

    /**
     * Discard the simulated conversation's order draft, so testing can
     * start over from a clean state. This never touches a real
     * WhatsAppConversation, WhatsAppOrderDraft, or Order.
     */
    public function resetSimulation(Request $request, ConversationEngine $engine): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();

        abort_unless($business->hasFeature(Feature::AI_ASSISTANT), 403, 'The AI Assistant is not available on your plan.');

        $engine->resetSimulation($business);

        return redirect()->route('ai.edit')->with('status', 'Simulation reset.');
    }
}
