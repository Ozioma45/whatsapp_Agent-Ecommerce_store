<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBusinessManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_admin_can_view_businesses(): void
    {
        $admin = $this->admin();
        Business::factory()->create(['name' => 'Mike Shoes']);

        $this->actingAs($admin)->get('/admin/businesses')->assertOk()->assertSee('Mike Shoes');
    }

    public function test_pagination_works(): void
    {
        $admin = $this->admin();

        foreach (range(0, 24) as $i) {
            Business::factory()->create(['name' => 'Business '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        $page1 = $this->actingAs($admin)->get('/admin/businesses');
        $page2 = $this->actingAs($admin)->get('/admin/businesses?page=2');

        $page1->assertOk();
        $page2->assertOk();

        // Businesses are ordered by name and paginated 20 per page, so the
        // 25th ("Business 24") must only appear on the second page.
        $page1->assertDontSee('Business 24');
        $page2->assertSee('Business 24');
    }

    public function test_search_by_business_name_works(): void
    {
        $admin = $this->admin();
        Business::factory()->create(['name' => 'Mike Shoes']);
        Business::factory()->create(['name' => 'Ada Beauty']);

        $response = $this->actingAs($admin)->get('/admin/businesses?search=Mike');

        $response->assertSee('Mike Shoes')->assertDontSee('Ada Beauty');
    }

    public function test_search_by_handle_works(): void
    {
        $admin = $this->admin();
        Business::factory()->create(['name' => 'Mike Shoes', 'handle' => 'mike-shoes-xyz']);
        Business::factory()->create(['name' => 'Ada Beauty']);

        $response = $this->actingAs($admin)->get('/admin/businesses?search=mike-shoes-xyz');

        $response->assertSee('Mike Shoes')->assertDontSee('Ada Beauty');
    }

    public function test_search_by_owner_email_works(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['email' => 'unique-owner@example.com']);
        Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Mike Shoes']);
        Business::factory()->create(['name' => 'Ada Beauty']);

        $response = $this->actingAs($admin)->get('/admin/businesses?search=unique-owner@example.com');

        $response->assertSee('Mike Shoes')->assertDontSee('Ada Beauty');
    }

    public function test_business_details_load(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create(['name' => 'Mike Shoes']);

        $response = $this->actingAs($admin)->get("/admin/businesses/{$business->handle}");

        $response->assertOk()->assertSee('Mike Shoes')->assertSee($business->owner->email);
    }
}
