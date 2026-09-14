<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_business_owner_can_update_their_store_settings(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $business = Business::create([
            'name' => "Mike's Fashion",
            'handle' => Business::generateUniqueHandle("Mike's Fashion"),
            'owner_id' => $user->id,
        ]);
        $business->setting()->create([]);

        $response = $this->actingAs($user)->put('/settings', [
            'name' => 'Mike’s Fashion Store',
            'description' => 'We sell affordable, stylish clothing.',
            'whatsapp_number' => '+2348012345678',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertRedirect(route('settings.edit'));

        $business->refresh();
        $this->assertSame('Mike’s Fashion Store', $business->name);
        $this->assertSame('We sell affordable, stylish clothing.', $business->setting->description);
        $this->assertSame('+2348012345678', $business->setting->whatsapp_number);
        Storage::disk('public')->assertExists($business->setting->logo);
    }

    public function test_the_business_handle_cannot_be_changed_from_the_settings_form(): void
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => "Mike's Fashion",
            'handle' => Business::generateUniqueHandle("Mike's Fashion"),
            'owner_id' => $user->id,
        ]);
        $business->setting()->create([]);

        $this->actingAs($user)->put('/settings', [
            'name' => 'Mike’s Fashion Store',
            'handle' => 'a-different-handle',
        ]);

        $this->assertSame('mikes-fashion', $business->fresh()->handle);
    }

    public function test_a_user_cannot_view_or_modify_another_users_business_data(): void
    {
        $ownerA = User::factory()->create();
        $businessA = Business::create([
            'name' => 'Business A',
            'handle' => Business::generateUniqueHandle('Business A'),
            'owner_id' => $ownerA->id,
        ]);
        $businessA->setting()->create(['whatsapp_number' => '+1000000000']);

        $ownerB = User::factory()->create();
        $businessB = Business::create([
            'name' => 'Business B',
            'handle' => Business::generateUniqueHandle('Business B'),
            'owner_id' => $ownerB->id,
        ]);
        $businessB->setting()->create(['whatsapp_number' => '+2000000000']);

        // Logged in as A, the dashboard must never show B's data.
        $this->actingAs($ownerA)
            ->get('/dashboard')
            ->assertDontSee('Business B')
            ->assertDontSee('+2000000000');

        // Even if A tries to sneak business B's id into the update payload,
        // only A's own business is ever touched.
        $this->actingAs($ownerA)->put('/settings', [
            'business_id' => $businessB->id,
            'name' => 'Business A Updated',
            'whatsapp_number' => '+1111111111',
        ]);

        $this->assertSame('Business A Updated', $businessA->fresh()->name);
        $this->assertSame('+1111111111', $businessA->fresh()->setting->whatsapp_number);

        // Business B is completely untouched.
        $this->assertSame('Business B', $businessB->fresh()->name);
        $this->assertSame('+2000000000', $businessB->fresh()->setting->whatsapp_number);
    }
}
