<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::create([
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => Hash::make('password123'),
        ]);

        Business::create([
            'name' => "Mike's Fashion",
            'handle' => Business::generateUniqueHandle("Mike's Fashion"),
            'owner_id' => $user->id,
        ])->setting()->create([]);

        $response = $this->post('/login', [
            'email' => 'mike@example.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_a_user_cannot_log_in_with_incorrect_password(): void
    {
        $user = User::create([
            'name' => 'Mike Owner',
            'email' => 'mike@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->post('/login', [
            'email' => 'mike@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_a_guest_cannot_access_the_dashboard(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
    }

    public function test_a_guest_cannot_access_store_settings(): void
    {
        $response = $this->get('/settings');

        $response->assertRedirect(route('login'));
    }
}
