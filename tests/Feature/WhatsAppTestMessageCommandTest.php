<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppIntegrationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTestMessageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_an_acting_user_to_be_identified(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $this->artisan('whatsapp:test-message', ['business' => $integration->business->handle, 'to' => '15551234567'])
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_user_who_does_not_own_the_business_and_is_not_admin(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create();
        $someoneElse = User::factory()->create(['role' => User::ROLE_BUSINESS_OWNER]);

        Http::fake();

        $this->artisan('whatsapp:test-message', [
            'business' => $integration->business->handle,
            'to' => '15551234567',
            '--as' => $someoneElse->email,
        ])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_owning_business_owner_can_send_a_test_message(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.CMD1']]], 200)]);
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $this->artisan('whatsapp:test-message', [
            'business' => $integration->business->handle,
            'to' => '15551234567',
            '--as' => $integration->business->owner->email,
        ])->assertExitCode(0)
            ->expectsOutputToContain('wamid.CMD1');
    }

    public function test_a_platform_admin_can_send_a_test_message_for_any_business(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.CMD2']]], 200)]);
        $integration = WhatsAppIntegrationSetting::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->artisan('whatsapp:test-message', [
            'business' => $integration->business->handle,
            'to' => '15551234567',
            '--as' => $admin->email,
        ])->assertExitCode(0);
    }

    public function test_a_business_owner_cannot_use_another_businesss_integration(): void
    {
        $ownerA = User::factory()->create(['role' => User::ROLE_BUSINESS_OWNER]);
        Business::factory()->create(['owner_id' => $ownerA->id]);
        $integrationB = WhatsAppIntegrationSetting::factory()->create();

        Http::fake();

        $this->artisan('whatsapp:test-message', [
            'business' => $integrationB->business->handle,
            'to' => '15551234567',
            '--as' => $ownerA->email,
        ])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_command_output_never_contains_the_access_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 401)]);
        $integration = WhatsAppIntegrationSetting::factory()->create(['access_token' => 'cli-secret-token']);

        $this->artisan('whatsapp:test-message', [
            'business' => $integration->business->handle,
            'to' => '15551234567',
            '--as' => $integration->business->owner->email,
        ])->expectsOutputToContain('Failed')
            ->doesntExpectOutputToContain('cli-secret-token');
    }
}
