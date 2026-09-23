<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_the_main_domain_shows_a_maintenance_page_when_enabled(): void
    {
        Setting::set(Setting::MAINTENANCE_MODE, '1');

        $response = $this->get('http://'.config('app.domain').'/');

        $response->assertStatus(503);
    }

    public function test_a_business_storefront_shows_a_maintenance_page_when_enabled(): void
    {
        Setting::set(Setting::MAINTENANCE_MODE, '1');
        $business = Business::factory()->create();

        $response = $this->get('http://'.$business->handle.'.'.config('app.domain').'/');

        $response->assertStatus(503);
    }

    public function test_the_main_domain_works_normally_when_maintenance_mode_is_off(): void
    {
        Setting::set(Setting::MAINTENANCE_MODE, '0');

        $this->get('http://'.config('app.domain').'/')->assertOk();
    }

    public function test_admins_can_still_access_the_admin_area_during_maintenance_mode(): void
    {
        Setting::set(Setting::MAINTENANCE_MODE, '1');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertOk();
    }
}
