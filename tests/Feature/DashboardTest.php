<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_view_their_dashboard(): void
    {
        $user = User::factory()->create(['name' => 'Mike Owner']);

        $business = Business::create([
            'name' => "Mike's Fashion",
            'handle' => Business::generateUniqueHandle("Mike's Fashion"),
            'owner_id' => $user->id,
        ]);
        $business->setting()->create(['whatsapp_number' => '+2348012345678']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee("Mike's Fashion");
        $response->assertSee('Mike Owner');
        $response->assertSee($user->email);
        $response->assertSee($business->handle);
        $response->assertSee('+2348012345678');
    }
}
