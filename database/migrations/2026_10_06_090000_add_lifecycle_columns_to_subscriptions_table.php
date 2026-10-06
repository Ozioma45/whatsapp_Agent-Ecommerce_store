<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Set when the owner asks not to continue past expires_at.
            // Deliberately separate from `status`: an active subscription
            // with cancelled_at set still keeps its paid entitlement until
            // expires_at — only the lifecycle command (subscriptions:expire)
            // turns that into status=cancelled once the date actually
            // passes. Never set on its own revokes anything.
            $table->timestamp('cancelled_at')->nullable()->after('decided_by');

            // Set once an "expiring soon" reminder has been sent for this
            // subscription, so the scheduled command never sends it twice.
            $table->timestamp('expiry_reminder_sent_at')->nullable()->after('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'expiry_reminder_sent_at']);
        });
    }
};
