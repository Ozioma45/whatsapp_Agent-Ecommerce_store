<?php

namespace Tests\Feature;

use App\Models\AiAssistantSetting;
use App\Models\Business;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\Ai\AiAssistantService;
use App\Support\Ai\AiContext;
use App\Support\Ai\AiProviderInterface;
use App\Support\Ai\ProductCatalogService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function businessOnPlan(string $slug, array $attributes = []): Business
    {
        $owner = User::factory()->create(['role' => User::ROLE_BUSINESS_OWNER]);

        return Business::factory()->create([
            'owner_id' => $owner->id,
            'plan_id' => Plan::where('slug', $slug)->firstOrFail()->id,
            ...$attributes,
        ]);
    }

    private function fakeProvider(): object
    {
        $provider = new class implements AiProviderInterface
        {
            public int $calls = 0;

            public ?AiContext $lastContext = null;

            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                $this->calls++;
                $this->lastContext = $context;

                return 'stub reply';
            }
        };

        $this->app->instance(AiProviderInterface::class, $provider);

        return $provider;
    }

    // --- Defaults -------------------------------------------------------

    public function test_a_new_business_gets_disabled_default_settings(): void
    {
        $business = Business::factory()->create();

        $settings = $business->aiAssistantSettings;

        $this->assertNotNull($settings);
        $this->assertFalse($settings->enabled);
        $this->assertSame('Hello! How can I help you today?', $settings->welcome_message);
        $this->assertSame(1, AiAssistantSetting::where('business_id', $business->id)->count());
    }

    public function test_registering_creates_default_settings(): void
    {
        $this->post('/register', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Fresh Store',
        ]);

        $business = Business::where('name', 'Fresh Store')->first();

        $this->assertNotNull($business);
        $this->assertFalse($business->aiAssistantSettings->enabled);
    }

    // --- Entitlement ----------------------------------------------------

    public function test_only_premium_is_entitled(): void
    {
        $this->assertFalse($this->businessOnPlan('standard')->hasFeature(Feature::AI_ASSISTANT));
        $this->assertFalse($this->businessOnPlan('pro')->hasFeature(Feature::AI_ASSISTANT));
        $this->assertTrue($this->businessOnPlan('premium')->hasFeature(Feature::AI_ASSISTANT));
    }

    public function test_non_entitled_business_sees_message_and_no_form(): void
    {
        $business = $this->businessOnPlan('standard');

        $this->actingAs($business->owner)->get('/ai-assistant')
            ->assertOk()
            ->assertSee('AI Assistant is available on eligible plans.')
            ->assertDontSee('Business instructions')
            ->assertDontSee('Upgrade');
    }

    public function test_non_entitled_business_cannot_update_settings(): void
    {
        foreach (['standard', 'pro'] as $slug) {
            $business = $this->businessOnPlan($slug);

            $this->actingAs($business->owner)->put('/ai-assistant', [
                'enabled' => '1',
                'tone' => 'friendly',
            ])->assertForbidden();

            $this->assertFalse($business->aiAssistantSettings()->first()->enabled);
        }
    }

    public function test_entitled_business_sees_the_form(): void
    {
        $business = $this->businessOnPlan('premium');

        $this->actingAs($business->owner)->get('/ai-assistant')
            ->assertOk()
            ->assertSee('Business instructions')
            ->assertSee('Welcome message')
            ->assertDontSee('AI Assistant is available on eligible plans.');
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/ai-assistant')->assertRedirect('/login');
        $this->put('/ai-assistant')->assertRedirect('/login');
    }

    // --- Settings -------------------------------------------------------

    public function test_entitled_business_can_save_settings(): void
    {
        $business = $this->businessOnPlan('premium');

        $this->actingAs($business->owner)->put('/ai-assistant', [
            'enabled' => '1',
            'welcome_message' => 'Welcome to our shop!',
            'business_instructions' => 'Mention our free gift wrapping.',
            'tone' => 'professional',
        ])->assertRedirect('/ai-assistant');

        $settings = $business->aiAssistantSettings()->first();

        $this->assertTrue($settings->enabled);
        $this->assertSame('Welcome to our shop!', $settings->welcome_message);
        $this->assertSame('Mention our free gift wrapping.', $settings->business_instructions);
        $this->assertSame('professional', $settings->tone);
    }

    public function test_entitled_business_can_disable_the_assistant(): void
    {
        $business = $this->businessOnPlan('premium');
        $business->aiAssistantSettings()->update(['enabled' => true]);

        $this->actingAs($business->owner)->put('/ai-assistant', ['tone' => 'concise'])
            ->assertRedirect('/ai-assistant');

        $this->assertFalse($business->aiAssistantSettings()->first()->enabled);
    }

    public function test_settings_validation(): void
    {
        $business = $this->businessOnPlan('premium');

        $this->actingAs($business->owner)->put('/ai-assistant', [
            'welcome_message' => str_repeat('a', 501),
            'business_instructions' => str_repeat('a', 2001),
            'tone' => 'sarcastic',
        ])->assertSessionHasErrors(['welcome_message', 'business_instructions', 'tone']);
    }

    // --- Tenant isolation ----------------------------------------------

    public function test_a_business_can_only_change_its_own_settings(): void
    {
        $a = $this->businessOnPlan('premium');
        $b = $this->businessOnPlan('premium');

        $this->actingAs($a->owner)->put('/ai-assistant', [
            'business_id' => $b->id,
            'enabled' => '1',
            'welcome_message' => 'From A',
            'tone' => 'friendly',
        ]);

        $this->assertSame('From A', $a->aiAssistantSettings()->first()->welcome_message);
        $this->assertSame(AiAssistantSetting::DEFAULT_WELCOME_MESSAGE, $b->aiAssistantSettings()->first()->welcome_message);
        $this->assertFalse($b->aiAssistantSettings()->first()->enabled);
    }

    public function test_a_business_never_sees_another_businesss_settings(): void
    {
        $a = $this->businessOnPlan('premium');
        $b = $this->businessOnPlan('premium');
        $b->aiAssistantSettings()->update(['business_instructions' => 'SECRET B INSTRUCTIONS']);

        $this->actingAs($a->owner)->get('/ai-assistant')
            ->assertOk()
            ->assertDontSee('SECRET B INSTRUCTIONS');
    }

    // --- Catalog --------------------------------------------------------

    public function test_catalog_returns_only_own_available_products_with_current_prices(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        $category = Category::factory()->create(['business_id' => $a->id, 'name' => 'Shoes']);

        $product = Product::factory()->create([
            'business_id' => $a->id, 'category_id' => $category->id, 'name' => 'Sneaker', 'price' => 10, 'is_available' => true,
        ]);
        Product::factory()->create(['business_id' => $a->id, 'name' => 'Hidden', 'is_available' => false]);
        Product::factory()->create(['business_id' => $b->id, 'name' => 'Other Biz Item', 'is_available' => true]);
        Category::factory()->create(['business_id' => $b->id, 'name' => 'Other Category']);

        $catalog = new ProductCatalogService($a);
        $products = $catalog->availableProducts();

        $this->assertSame(['Sneaker'], $products->pluck('name')->all());
        $this->assertSame('10.00', $products->first()['price']);
        $this->assertSame('Shoes', $products->first()['category']);
        $this->assertSame(['Shoes'], $catalog->categories()->all());

        $product->update(['price' => 25.5]);

        $this->assertSame('25.50', (new ProductCatalogService($a))->availableProducts()->first()['price']);
    }

    // --- Service --------------------------------------------------------

    public function test_service_returns_null_and_skips_provider_when_plan_lacks_feature(): void
    {
        $provider = $this->fakeProvider();
        $business = $this->businessOnPlan('standard');
        $business->aiAssistantSettings()->update(['enabled' => true]);

        $this->assertNull(app(AiAssistantService::class)->respond($business->fresh(), 'hi'));
        $this->assertSame(0, $provider->calls);
    }

    public function test_service_returns_null_when_business_has_not_enabled_it(): void
    {
        $provider = $this->fakeProvider();
        $business = $this->businessOnPlan('premium');

        $this->assertNull(app(AiAssistantService::class)->respond($business, 'hi'));
        $this->assertSame(0, $provider->calls);
    }

    public function test_service_calls_provider_with_only_own_context_when_available(): void
    {
        $provider = $this->fakeProvider();
        $a = $this->businessOnPlan('premium', ['name' => 'Alpha Shop']);
        $b = $this->businessOnPlan('premium', ['name' => 'Beta Shop']);
        $a->aiAssistantSettings()->update(['enabled' => true, 'business_instructions' => 'Be nice']);
        Product::factory()->create(['business_id' => $a->id, 'name' => 'Alpha Item', 'price' => 5]);
        Product::factory()->create(['business_id' => $b->id, 'name' => 'Beta Item']);

        $reply = app(AiAssistantService::class)->respond($a->fresh(), 'what do you sell?');

        $this->assertSame('stub reply', $reply);
        $this->assertSame(1, $provider->calls);

        $sections = $provider->lastContext->sections();
        $encoded = json_encode($sections);

        $this->assertSame('Alpha Shop', $sections['store_data']['business']['name']);
        $this->assertSame(['Alpha Item'], array_column($sections['store_data']['products'], 'name'));
        $this->assertStringNotContainsString('Beta', $encoded);
        $this->assertStringNotContainsString('what do you sell?', $encoded);
        $this->assertSame('Be nice', $sections['business_instructions']['text']);
        $this->assertFalse($sections['business_instructions']['trusted']);
        $this->assertNotEmpty($sections['platform_rules']);
        $this->assertStringNotContainsString($a->owner->email, $encoded);
    }

    // --- Admin ----------------------------------------------------------

    public function test_admin_sees_eligibility_and_enabled_state(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $premium = $this->businessOnPlan('premium');
        $premium->aiAssistantSettings()->update(['enabled' => true]);
        $standard = $this->businessOnPlan('standard');

        $this->actingAs($admin)->get("/admin/businesses/{$premium->handle}")
            ->assertOk()->assertSee('AI Assistant')->assertSeeInOrder(['Plan eligible', 'Yes', 'Enabled', 'Yes']);

        $this->actingAs($admin)->get("/admin/businesses/{$standard->handle}")
            ->assertOk()->assertSeeInOrder(['Plan eligible', 'No', 'Enabled', 'No']);
    }

    public function test_admin_cannot_bypass_entitlement(): void
    {
        $provider = $this->fakeProvider();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $business = $this->businessOnPlan('standard', ['owner_id' => $admin->id]);
        $business->aiAssistantSettings()->update(['enabled' => true]);

        $this->actingAs($admin)->put('/ai-assistant', ['enabled' => '1'])->assertForbidden();
        $this->assertNull(app(AiAssistantService::class)->respond($business->fresh(), 'hi'));
        $this->assertSame(0, $provider->calls);
    }
}
