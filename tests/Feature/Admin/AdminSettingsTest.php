<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_admin_can_view_settings(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Platform settings');
    }

    public function test_admin_can_update_supported_settings(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->patch('/admin/settings', [
            'platform_name' => 'New Platform Name',
            'platform_description' => 'New description',
            'support_email' => 'support@example.com',
            'support_whatsapp' => '08012345678',
            'default_plan' => Plan::PRO,
            'maintenance_mode' => '1',
        ]);

        $response->assertRedirect(route('admin.settings.edit'));
        $this->assertSame('New Platform Name', Setting::get(Setting::PLATFORM_NAME));
        $this->assertSame(Plan::PRO, Setting::get(Setting::DEFAULT_PLAN));
        $this->assertTrue(Setting::getBool(Setting::MAINTENANCE_MODE));
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->patch('/admin/settings', [
            'platform_name' => '',
            'support_email' => 'not-an-email',
            'default_plan' => 'nonexistent-plan',
        ]);

        $response->assertSessionHasErrors(['platform_name', 'support_email', 'default_plan']);
    }

    public function test_only_supported_keys_can_be_written(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin)->patch('/admin/settings', [
            'platform_name' => 'Valid Name',
            'default_plan' => Plan::STANDARD,
            'some_arbitrary_key' => 'malicious-value',
        ]);

        $this->assertNull(Setting::get('some_arbitrary_key'));
    }

    public function test_default_plan_affects_new_registrations(): void
    {
        $this->seed(PlanSeeder::class);
        Setting::set(Setting::DEFAULT_PLAN, Plan::PRO);

        $this->post('/register', [
            'business_name' => 'New Biz',
            'name' => 'New Owner',
            'email' => 'newowner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $business = Business::where('name', 'New Biz')->firstOrFail();
        $this->assertSame(Plan::PRO, $business->plan->slug);
    }

    public function test_changing_default_plan_does_not_modify_existing_businesses(): void
    {
        $this->seed(PlanSeeder::class);
        $standard = Plan::where('slug', Plan::STANDARD)->firstOrFail();
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'plan_id' => $standard->id]);

        Setting::set(Setting::DEFAULT_PLAN, Plan::PREMIUM);

        $this->assertSame(Plan::STANDARD, $business->fresh()->plan->slug);
    }
}
