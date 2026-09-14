<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicStoreTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): string
    {
        return config('app.domain');
    }

    public function test_a_valid_business_subdomain_resolves_to_the_correct_business(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Mike Shoes',
            'handle' => Business::generateUniqueHandle('Mike Shoes'),
            'owner_id' => $owner->id,
        ]);
        $business->setting()->create([
            'description' => 'Quality shoes for everyone.',
            'whatsapp_number' => '+2348011112222',
        ]);

        $response = $this->get('http://mike-shoes.'.$this->domain().'/');

        $response->assertOk();
        $response->assertSee('Mike Shoes');
        $response->assertSee('Quality shoes for everyone.');
        $response->assertSee('+2348011112222');
    }

    public function test_an_unknown_subdomain_returns_a_404(): void
    {
        $response = $this->get('http://unknown-business.'.$this->domain().'/');

        $response->assertNotFound();
    }

    public function test_a_businesses_subdomain_never_displays_another_businesses_data(): void
    {
        $ownerA = User::factory()->create();
        $businessA = Business::create([
            'name' => 'Mike Shoes',
            'handle' => Business::generateUniqueHandle('Mike Shoes'),
            'owner_id' => $ownerA->id,
        ]);
        $businessA->setting()->create(['whatsapp_number' => '+1000000000']);

        $ownerB = User::factory()->create();
        $businessB = Business::create([
            'name' => 'Beauty By Ada',
            'handle' => Business::generateUniqueHandle('Beauty By Ada'),
            'owner_id' => $ownerB->id,
        ]);
        $businessB->setting()->create(['whatsapp_number' => '+2000000000']);

        $this->get('http://'.$businessA->handle.'.'.$this->domain().'/')
            ->assertOk()
            ->assertSee('Mike Shoes')
            ->assertDontSee('Beauty By Ada')
            ->assertDontSee('+2000000000');

        $this->get('http://'.$businessB->handle.'.'.$this->domain().'/')
            ->assertOk()
            ->assertSee('Beauty By Ada')
            ->assertDontSee('Mike Shoes')
            ->assertDontSee('+1000000000');
    }

    public function test_the_main_domain_still_works(): void
    {
        $response = $this->get('http://'.$this->domain().'/');

        $response->assertOk();
    }

    public function test_dashboard_and_settings_remain_accessible_from_the_main_domain(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Mike Shoes',
            'handle' => Business::generateUniqueHandle('Mike Shoes'),
            'owner_id' => $owner->id,
        ]);
        $business->setting()->create([]);

        $this->actingAs($owner)
            ->get('http://'.$this->domain().'/dashboard')
            ->assertOk();

        $this->actingAs($owner)
            ->get('http://'.$this->domain().'/settings')
            ->assertOk();
    }

    public function test_the_generated_public_store_url_uses_the_business_handle(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Mike Shoes',
            'handle' => Business::generateUniqueHandle('Mike Shoes'),
            'owner_id' => $owner->id,
        ]);

        $this->assertSame(
            'http://mike-shoes.'.$this->domain(),
            $business->publicUrl()
        );
    }
}
