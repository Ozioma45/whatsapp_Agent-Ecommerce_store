<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_admin_can_view_users(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Jane Doe']);

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('Jane Doe');
    }

    public function test_admin_can_view_a_single_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Jane Doe']);

        $this->actingAs($admin)->get("/admin/users/{$user->id}")->assertOk()->assertSee('Jane Doe');
    }

    public function test_admin_cannot_demote_themselves_when_they_are_the_only_admin(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->patch("/admin/users/{$admin->id}/role", ['role' => User::ROLE_BUSINESS_OWNER]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_an_admin_can_demote_a_different_admin_when_more_than_one_admin_exists(): void
    {
        $admin = $this->admin();
        $secondAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->patch("/admin/users/{$secondAdmin->id}/role", ['role' => User::ROLE_BUSINESS_OWNER]);

        $response->assertRedirect(route('admin.users.show', $secondAdmin));
        $this->assertFalse($secondAdmin->fresh()->isAdmin());
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_a_normal_business_user_cannot_change_roles(): void
    {
        $owner = User::factory()->create();
        Business::factory()->create(['owner_id' => $owner->id]);
        $target = User::factory()->create();

        $response = $this->actingAs($owner)->patch("/admin/users/{$target->id}/role", ['role' => User::ROLE_ADMIN]);

        $response->assertForbidden();
        $this->assertFalse($target->fresh()->isAdmin());
    }
}
