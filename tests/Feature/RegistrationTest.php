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

    public function test_the_business_slug_is_generated_from_the_business_name(): void
    {
        $this->post('/register', [
            'business_name' => "Mike's Fashion",
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $business = Business::where('name', "Mike's Fashion")->firstOrFail();

        $this->assertSame('mikes-fashion', $business->slug);
    }

    public function test_duplicate_business_names_produce_unique_slugs(): void
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

        $this->assertDatabaseHas('businesses', ['slug' => 'mikes-fashion']);
        $this->assertDatabaseHas('businesses', ['slug' => 'mikes-fashion-2']);
    }
}
