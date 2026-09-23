<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_make_promotes_the_specified_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('admin:make', ['email' => 'owner@example.com'])->assertExitCode(0);

        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_it_fails_clearly_for_an_email_that_does_not_exist(): void
    {
        $this->artisan('admin:make', ['email' => 'nobody@example.com'])->assertExitCode(1);

        $this->assertSame(0, User::where('role', User::ROLE_ADMIN)->count());
    }

    public function test_existing_user_data_remains_unchanged(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'name' => 'Original Name',
            'password' => Hash::make('original-password'),
        ]);
        $originalPasswordHash = $user->password;

        $this->artisan('admin:make', ['email' => 'owner@example.com']);

        $fresh = $user->fresh();
        $this->assertSame('Original Name', $fresh->name);
        $this->assertSame($originalPasswordHash, $fresh->password);
    }

    public function test_it_does_not_change_the_users_business(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $business = Business::factory()->create(['owner_id' => $user->id]);

        $this->artisan('admin:make', ['email' => 'owner@example.com']);

        $this->assertSame($business->id, $user->fresh()->business->id);
    }

    public function test_it_does_not_create_a_duplicate_user(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('admin:make', ['email' => 'owner@example.com']);

        $this->assertSame(1, User::where('email', 'owner@example.com')->count());
    }

    public function test_it_reports_safely_when_the_user_is_already_an_admin(): void
    {
        User::factory()->create(['email' => 'owner@example.com', 'role' => User::ROLE_ADMIN]);

        $this->artisan('admin:make', ['email' => 'owner@example.com'])
            ->expectsOutputToContain('already an administrator')
            ->assertExitCode(0);

        $this->assertSame(1, User::where('email', 'owner@example.com')->count());
    }
}
