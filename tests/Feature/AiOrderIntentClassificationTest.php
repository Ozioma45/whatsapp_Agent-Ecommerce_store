<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\WhatsAppIntegrationSetting;
use App\Support\Ai\AiContext;
use App\Support\Ai\AiProviderInterface;
use App\Support\Ai\ConversationEngine;
use App\Support\Ai\ConversationOutcome;
use App\Support\WhatsApp\IncomingWhatsAppMessage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression coverage for Phase 9E.1: a product being *mentioned* in a
 * message must never, on its own, add it to the order draft. Only a
 * message that both names a real product and carries explicit purchase
 * intent may change the draft — see OrderIntentParser.
 *
 * The original bug: "Do you have Sneaker male" was answered with "Added 3
 * x Sneaker male to your order" — an availability question was treated as
 * an add-to-cart instruction, and a quantity was invented from nowhere.
 */
class AiOrderIntentClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
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

    private function send(Business $business, string $text, ?string $id = null): ConversationOutcome
    {
        $message = new IncomingWhatsAppMessage(
            messageId: $id ?? 'wamid.'.uniqid('', true),
            from: '15550001111',
            timestamp: now(),
            type: 'text',
            text: $text,
        );

        return app(ConversationEngine::class)->handleIncomingMessage($business, $message);
    }

    private function draftItemCount(Business $business): int
    {
        return $business->whatsAppConversations()->first()?->orderDraft?->items()->count() ?? 0;
    }

    // --- The exact reported bug --------------------------------------

    public function test_the_original_reported_bug_is_fixed(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 500000]);

        $outcome = $this->send($business, 'Do you have Sneaker male');

        $this->assertStringNotContainsString('Added', $outcome->replyText);
        $this->assertStringContainsString('Sneaker male', $outcome->replyText);
        $this->assertStringContainsString('500,000.00', $outcome->replyText);
        $this->assertSame(0, $this->draftItemCount($business));
        $this->assertSame(0, Order::count());
    }

    // --- Availability / information inquiries ---------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function availabilityQuestions(): array
    {
        return [
            'do you have' => ['Do you have Sneaker male?'],
            'is available' => ['Is Sneaker male available?'],
            'how much' => ['How much is Sneaker male?'],
            'price of' => ["What's the price of Sneaker male?"],
            'do you sell' => ['Do you sell Sneaker male?'],
            'can i see' => ['Can I see Sneaker male?'],
        ];
    }

    #[DataProvider('availabilityQuestions')]
    public function test_availability_questions_never_modify_an_empty_draft(string $question): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);

        $outcome = $this->send($business, $question);

        $this->assertTrue($outcome->successful);
        $this->assertStringContainsString('Sneaker male', $outcome->replyText);
        $this->assertStringContainsString('45.00', $outcome->replyText);
        $this->assertStringNotContainsString('Added', $outcome->replyText);
        $this->assertSame(0, $this->draftItemCount($business));
        $this->assertSame(0, Order::count());
    }

    public function test_an_inquiry_about_a_product_not_in_the_catalogue_falls_through_to_the_general_reply(): void
    {
        $business = $this->readyBusiness();
        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                return 'We do not carry that item.';
            }
        });

        $outcome = $this->send($business, 'Do you have a flying carpet?');

        $this->assertSame('We do not carry that item.', $outcome->replyText);
        $this->assertSame(0, $this->draftItemCount($business));
    }

    // --- Explicit purchase requests --------------------------------------

    public function test_a_purchase_request_without_a_quantity_defaults_to_one(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);

        $outcome = $this->send($business, 'I want Sneaker male');

        $this->assertStringContainsString('1 x Sneaker male', $outcome->replyText);
        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(1, $draft->items()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_add_two_adds_exactly_two_units(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);

        $this->send($business, 'Add 2 Sneaker male');

        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(2, $draft->items()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_a_worded_quantity_of_three_pairs_adds_exactly_three_units(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);

        $this->send($business, 'I want 3 pairs of Sneaker male');

        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(3, $draft->items()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_an_ambiguous_quantity_asks_for_clarification_instead_of_guessing(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);

        $outcome = $this->send($business, 'I want a few Sneaker male');

        $this->assertStringContainsString('How many', $outcome->replyText);
        $this->assertSame(0, $this->draftItemCount($business));
    }

    public function test_multiple_conflicting_numbers_are_treated_as_ambiguous(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);

        $outcome = $this->send($business, 'I want 2 or 3 Sneaker male');

        $this->assertStringContainsString('How many', $outcome->replyText);
        $this->assertSame(0, $this->draftItemCount($business));
    }

    public function test_a_product_name_mentioned_without_purchase_intent_does_not_modify_the_draft(): void
    {
        $business = $this->readyBusiness();
        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                return 'Noted!';
            }
        });
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);

        $outcome = $this->send($business, 'My friend has Sneaker male already');

        $this->assertSame('Noted!', $outcome->replyText);
        $this->assertSame(0, $this->draftItemCount($business));
    }

    // --- Existing draft protection ---------------------------------------

    public function test_an_availability_question_never_changes_an_existing_draft(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Sneaker male', 'price' => 45]);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Red Cap', 'price' => 5]);

        $this->send($business, 'I want 2 Sneaker male', id: 'wamid.1');

        foreach ([
            'Do you have another Sneaker male?',
            'How much is Sneaker male?',
            'Is Sneaker male available?',
        ] as $index => $question) {
            $this->send($business, $question, id: "wamid.q{$index}");
        }

        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(1, $draft->items()->count());
        $this->assertSame(2, $draft->items()->first()->quantity);
    }
}
