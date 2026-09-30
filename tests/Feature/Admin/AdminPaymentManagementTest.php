<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Business::factory()->create(['owner_id' => $admin->id]);

        return $admin;
    }

    public function test_admin_can_view_the_payments_list(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create(['name' => 'Acme Store']);
        PaymentTransaction::factory()->for($business)->successful()->create();

        $this->actingAs($admin)->get('/admin/payments')
            ->assertOk()
            ->assertSee('Acme Store');
    }

    public function test_admin_can_view_a_payments_detail(): void
    {
        $admin = $this->admin();
        $payment = PaymentTransaction::factory()->successful()->create();

        $this->actingAs($admin)->get("/admin/payments/{$payment->id}")
            ->assertOk()
            ->assertSee($payment->reference);
    }

    public function test_non_admin_users_cannot_access_admin_payment_records(): void
    {
        $owner = User::factory()->create();
        Business::factory()->create(['owner_id' => $owner->id]);
        $payment = PaymentTransaction::factory()->create();

        $this->actingAs($owner)->get('/admin/payments')->assertForbidden();
        $this->actingAs($owner)->get("/admin/payments/{$payment->id}")->assertForbidden();
    }

    public function test_a_guest_cannot_access_admin_payment_records(): void
    {
        $this->get('/admin/payments')->assertRedirect('/login');
    }

    public function test_no_secret_key_appears_in_the_admin_payment_pages(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_super_secret_value']);
        $admin = $this->admin();
        $payment = PaymentTransaction::factory()->successful()->create();

        $response = $this->actingAs($admin)->get("/admin/payments/{$payment->id}");

        $response->assertOk();
        $response->assertDontSee('sk_test_super_secret_value');
    }
}
