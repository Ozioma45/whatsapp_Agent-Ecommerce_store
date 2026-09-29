<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\WhatsAppConversationMessage;
use App\Models\WhatsAppIntegrationSetting;
use App\Support\Ai\AiContext;
use App\Support\Ai\AiProviderInterface;
use App\Support\Ai\ConversationEngine;
use App\Support\Ai\ConversationOutcome;
use App\Support\WhatsApp\IncomingWhatsAppMessage;
use App\Support\WhatsApp\WhatsAppSendResult;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ConversationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function readyBusiness(): Business
    {
        $business = Business::factory()->create([
            'plan_id' => Plan::where('slug', 'premium')->firstOrFail()->id,
        ]);
        $business->aiAssistantSettings()->update(['enabled' => true]);
        WhatsAppIntegrationSetting::factory()->create(['business_id' => $business->id]);

        return $business->fresh();
    }

    private function incomingMessage(string $from = '15550001111', ?string $text = 'Hello', ?string $id = null): IncomingWhatsAppMessage
    {
        return new IncomingWhatsAppMessage(
            messageId: $id ?? 'wamid.'.uniqid('', true),
            from: $from,
            timestamp: now(),
            type: 'text',
            text: $text,
        );
    }

    /**
     * @return object{calls: array<int, array{context: AiContext, message: string}>}
     */
    private function fakeProvider(?\Closure $behaviour = null): object
    {
        $provider = new class($behaviour) implements AiProviderInterface
        {
            /** @var array<int, array{context: AiContext, message: string}> */
            public array $calls = [];

            public function __construct(private readonly ?\Closure $behaviour) {}

            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                $this->calls[] = ['context' => $context, 'message' => $customerMessage];

                return $this->behaviour ? ($this->behaviour)($context, $customerMessage) : 'Generated reply';
            }
        };

        $this->app->instance(AiProviderInterface::class, $provider);

        return $provider;
    }

    private function engine(): ConversationEngine
    {
        return app(ConversationEngine::class);
    }

    // --- Conversation processing ------------------------------------------

    public function test_a_valid_message_produces_a_response_and_saves_both_messages(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);
        $provider = $this->fakeProvider();
        $business = $this->readyBusiness();

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage(text: 'Hi there', id: 'wamid.IN1'));

        $this->assertTrue($outcome->successful);
        $this->assertSame(ConversationOutcome::RESPONDED, $outcome->status);
        $this->assertSame('Generated reply', $outcome->replyText);
        $this->assertCount(1, $provider->calls);

        $conversation = $business->whatsAppConversations()->firstOrFail();
        $this->assertSame('15550001111', $conversation->customer_whatsapp_number);
        $this->assertSame(2, $conversation->messages()->count());

        $this->assertDatabaseHas('whatsapp_conversation_messages', [
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppConversationMessage::DIRECTION_INBOUND,
            'content' => 'Hi there',
            'whatsapp_message_id' => 'wamid.IN1',
        ]);
        $this->assertDatabaseHas('whatsapp_conversation_messages', [
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppConversationMessage::DIRECTION_OUTBOUND,
            'content' => 'Generated reply',
            'whatsapp_message_id' => 'wamid.OUT1',
        ]);
    }

    public function test_the_correct_business_catalogue_is_used(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $a = $this->readyBusiness();
        $b = $this->readyBusiness();
        Product::factory()->create(['business_id' => $a->id, 'name' => 'Alpha Item']);
        Product::factory()->create(['business_id' => $b->id, 'name' => 'Beta Item']);

        $provider = $this->fakeProvider();

        $this->engine()->handleIncomingMessage($a, $this->incomingMessage());

        $names = array_column($provider->calls[0]['context']->products, 'name');
        $this->assertSame(['Alpha Item'], $names);
    }

    public function test_the_correct_business_instructions_are_used(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $business = $this->readyBusiness();
        $business->aiAssistantSettings()->update(['business_instructions' => 'Always mention free delivery.']);
        $provider = $this->fakeProvider();

        $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertSame('Always mention free delivery.', $provider->calls[0]['context']->businessInstructions);
    }

    public function test_conversation_history_is_loaded_only_for_the_matching_customer_and_business(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $business = $this->readyBusiness();
        $otherBusiness = $this->readyBusiness();

        $sameBusinessOtherCustomer = $business->whatsAppConversations()->create(['customer_whatsapp_number' => '15559999999']);
        $sameBusinessOtherCustomer->messages()->create([
            'direction' => 'inbound', 'message_type' => 'text', 'content' => 'unrelated-customer',
            'whatsapp_message_id' => 'wamid.other-customer', 'occurred_at' => now(),
        ]);

        $otherBusinessSameCustomer = $otherBusiness->whatsAppConversations()->create(['customer_whatsapp_number' => '15550001111']);
        $otherBusinessSameCustomer->messages()->create([
            'direction' => 'inbound', 'message_type' => 'text', 'content' => 'unrelated-business',
            'whatsapp_message_id' => 'wamid.other-business', 'occurred_at' => now(),
        ]);

        $matching = $business->whatsAppConversations()->create(['customer_whatsapp_number' => '15550001111']);
        $matching->messages()->create([
            'direction' => 'inbound', 'message_type' => 'text', 'content' => 'earlier message',
            'whatsapp_message_id' => 'wamid.earlier', 'occurred_at' => now()->subMinute(),
        ]);

        $provider = $this->fakeProvider();

        $this->engine()->handleIncomingMessage($business, $this->incomingMessage(from: '15550001111', text: 'follow up', id: 'wamid.followup'));

        $texts = array_column($provider->calls[0]['context']->conversationHistory, 'text');

        $this->assertContains('earlier message', $texts);
        $this->assertNotContains('unrelated-customer', $texts);
        $this->assertNotContains('unrelated-business', $texts);
    }

    public function test_a_duplicate_incoming_message_is_not_processed_twice(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $provider = $this->fakeProvider();
        $business = $this->readyBusiness();
        $message = $this->incomingMessage(id: 'wamid.dup');

        $first = $this->engine()->handleIncomingMessage($business, $message);
        $second = $this->engine()->handleIncomingMessage($business, $message);

        $this->assertTrue($first->successful);
        $this->assertFalse($second->successful);
        $this->assertSame(ConversationOutcome::ALREADY_PROCESSED, $second->status);
        $this->assertCount(1, $provider->calls);
        $this->assertSame(2, WhatsAppConversationMessage::count());
    }

    // --- Access and entitlement -------------------------------------------

    public function test_a_non_eligible_business_never_invokes_the_provider(): void
    {
        Http::fake();
        $provider = $this->fakeProvider();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', 'standard')->firstOrFail()->id]);
        $business->aiAssistantSettings()->update(['enabled' => true]);
        WhatsAppIntegrationSetting::factory()->create(['business_id' => $business->id]);

        $outcome = $this->engine()->handleIncomingMessage($business->fresh(), $this->incomingMessage());

        $this->assertSame(ConversationOutcome::NOT_AVAILABLE, $outcome->status);
        $this->assertSame([], $provider->calls);
        Http::assertNothingSent();
    }

    public function test_a_disabled_assistant_never_invokes_the_provider(): void
    {
        Http::fake();
        $provider = $this->fakeProvider();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', 'premium')->firstOrFail()->id]);
        WhatsAppIntegrationSetting::factory()->create(['business_id' => $business->id]);

        $outcome = $this->engine()->handleIncomingMessage($business->fresh(), $this->incomingMessage());

        $this->assertSame(ConversationOutcome::NOT_AVAILABLE, $outcome->status);
        $this->assertSame([], $provider->calls);
        Http::assertNothingSent();
    }

    public function test_a_disconnected_integration_cannot_send_but_still_fails_safely(): void
    {
        Http::fake();
        $this->fakeProvider();
        $business = $this->readyBusiness();
        $business->whatsAppIntegrationSetting->update(['status' => WhatsAppIntegrationSetting::STATUS_DISCONNECTED]);

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertFalse($outcome->successful);
        $this->assertSame(ConversationOutcome::SEND_FAILED, $outcome->status);
        Http::assertNothingSent();
        // The inbound message is still recorded even though a reply couldn't be sent.
        $this->assertSame(1, WhatsAppConversationMessage::count());
    }

    public function test_business_a_never_uses_business_bs_credentials_or_conversations(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $this->fakeProvider();
        $a = $this->readyBusiness();
        $b = $this->readyBusiness();

        $this->engine()->handleIncomingMessage($a, $this->incomingMessage(from: '15550001111'));

        Http::assertSent(function (HttpRequest $request) use ($a, $b) {
            return str_contains($request->url(), $a->whatsAppIntegrationSetting->phone_number_id)
                && ! str_contains($request->url(), $b->whatsAppIntegrationSetting->phone_number_id);
        });

        $this->assertSame(0, $b->whatsAppConversations()->count());
    }

    // --- Unsupported messages ----------------------------------------------

    public function test_an_unsupported_or_empty_message_never_invokes_the_provider(): void
    {
        Http::fake();
        $provider = $this->fakeProvider();
        $business = $this->readyBusiness();

        $blank = $this->engine()->handleIncomingMessage($business, $this->incomingMessage(text: ''));
        $missing = $this->engine()->handleIncomingMessage($business, $this->incomingMessage(text: null, id: 'wamid.null'));

        $this->assertSame(ConversationOutcome::UNSUPPORTED_MESSAGE, $blank->status);
        $this->assertSame(ConversationOutcome::UNSUPPORTED_MESSAGE, $missing->status);
        $this->assertSame([], $provider->calls);
        Http::assertNothingSent();
    }

    // --- Failure handling ---------------------------------------------------

    public function test_a_provider_exception_is_handled_safely(): void
    {
        Http::fake();
        $business = $this->readyBusiness();
        $provider = new class implements AiProviderInterface
        {
            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                throw new RuntimeException('boom');
            }
        };
        $this->app->instance(AiProviderInterface::class, $provider);

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertSame(ConversationOutcome::PROVIDER_UNAVAILABLE, $outcome->status);
        Http::assertNothingSent();
    }

    public function test_an_empty_provider_response_is_never_sent(): void
    {
        Http::fake();
        $business = $this->readyBusiness();
        $this->fakeProvider(fn () => '   ');

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertSame(ConversationOutcome::EMPTY_RESPONSE, $outcome->status);
        Http::assertNothingSent();
    }

    public function test_a_whatsapp_authentication_failure_is_reported_and_nothing_is_saved_as_sent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad token']], 401)]);
        $this->fakeProvider();
        $business = $this->readyBusiness();

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertSame(ConversationOutcome::SEND_FAILED, $outcome->status);
        $this->assertSame(WhatsAppSendResult::AUTH_FAILURE, $outcome->sendResult->status);
        $this->assertSame(0, WhatsAppConversationMessage::where('direction', 'outbound')->count());
    }

    public function test_a_whatsapp_rate_limit_is_reported_safely(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'slow down']], 429)]);
        $this->fakeProvider();
        $business = $this->readyBusiness();

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertSame(WhatsAppSendResult::RATE_LIMITED, $outcome->sendResult->status);
    }

    public function test_a_network_failure_is_reported_safely(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timeout');
        });
        $this->fakeProvider();
        $business = $this->readyBusiness();

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertSame(WhatsAppSendResult::NETWORK_ERROR, $outcome->sendResult->status);
    }

    public function test_an_unexpected_whatsapp_response_is_reported_safely(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'oops']], 500)]);
        $this->fakeProvider();
        $business = $this->readyBusiness();

        $outcome = $this->engine()->handleIncomingMessage($business, $this->incomingMessage());

        $this->assertSame(WhatsAppSendResult::UNEXPECTED_ERROR, $outcome->sendResult->status);
    }

    // --- No credential leakage ---------------------------------------------

    public function test_the_outcome_never_carries_the_access_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad token']], 401)]);
        $this->fakeProvider();
        $business = $this->readyBusiness();
        $business->whatsAppIntegrationSetting->update(['access_token' => 'do-not-leak-me']);

        $outcome = $this->engine()->handleIncomingMessage($business->fresh(), $this->incomingMessage());

        $this->assertStringNotContainsString('do-not-leak-me', json_encode($outcome));
    }
}
