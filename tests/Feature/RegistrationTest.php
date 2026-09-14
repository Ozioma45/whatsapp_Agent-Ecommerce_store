<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register(): void
    {
        $response = $this->post('/register', [
            'business_name' => "Mike's Fashion",
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('users', [
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
        ]);
    }

    public function test_registration_creates_the_users_business(): void
    {
        $this->post('/register', [
            'business_name' => "Mike's Fashion",
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'mike@example.com')->firstOrFail();

        $this->assertDatabaseHas('businesses', [
            'name' => "Mike's Fashion",
            'owner_id' => $user->id,
        ]);
        $this->assertNotNull($user->business);
    }

    public function test_registration_creates_the_business_settings(): void
    {
        $this->post('/register', [
            'business_name' => "Mike's Fashion",
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $business = Business::where('name', "Mike's Fashion")->firstOrFail();

        $this->assertDatabaseHas('business_settings', [
            'business_id' => $business->id,
        ]);
        $this->assertNotNull($business->setting);
    }

    public function test_a_business_receives_a_handle_when_registered(): void
    {
        $this->post('/register', [
            'business_name' => "Mike's Fashion",
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $business = Business::where('name', "Mike's Fashion")->firstOrFail();

        $this->assertNotEmpty($business->handle);
    }

    public function test_the_handle_is_generated_correctly_from_the_business_name(): void
    {
        $this->post('/register', [
            'business_name' => "John's Electronics",
            'name' => 'John Owner',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $business = Business::where('name', "John's Electronics")->firstOrFail();

        $this->assertSame('johns-electronics', $business->handle);
    }

    public function test_duplicate_business_names_produce_unique_handles(): void
    {
        $this->post('/register', [
            'business_name' => "Mike's Fashion",
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->post('/logout');

        $this->post('/register', [
            'business_name' => "Mike's Fashion",
            'name' => 'Another Mike',
            'email' => 'another-mike@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertDatabaseHas('businesses', ['handle' => 'mikes-fashion']);
        $this->assertDatabaseHas('businesses', ['handle' => 'mikes-fashion-2']);
    }

    public function test_handles_are_unique_at_the_database_level(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $owner1 = User::factory()->create();
        Business::create(['name' => 'One', 'handle' => 'duplicate-handle', 'owner_id' => $owner1->id]);

        $owner2 = User::factory()->create();
        Business::create(['name' => 'Two', 'handle' => 'duplicate-handle', 'owner_id' => $owner2->id]);
    }

    public function test_reserved_handles_cannot_be_assigned_to_a_business(): void
    {
        $this->post('/register', [
            'business_name' => 'Admin',
            'name' => 'Some Owner',
            'email' => 'someowner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $business = Business::where('name', 'Admin')->firstOrFail();

        $this->assertNotSame('admin', $business->handle);
        $this->assertNotContains($business->handle, Business::RESERVED_HANDLES);
    }
}
