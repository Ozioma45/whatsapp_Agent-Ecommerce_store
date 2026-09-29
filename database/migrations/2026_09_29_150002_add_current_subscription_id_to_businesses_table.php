<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('current_subscription_id')->nullable()->after('plan_id')
                ->constrained('subscriptions')->nullOnDelete();
        });

        // Every existing business gets an initial "active" subscription
        // mirroring its current plan, so pre-Phase-10A businesses behave
        // identically under the new subscription-standing gate (see
        // Business::hasActiveSubscriptionStanding() — a business with no
        // subscription record at all is *also* treated as being in good
        // standing there, purely as a defensive fallback; this backfill is
        // what makes that fallback unnecessary in practice).
        $now = now();

        DB::table('businesses')->whereNull('current_subscription_id')->orderBy('id')
            ->chunkById(100, function ($businesses) use ($now) {
                foreach ($businesses as $business) {
                    $subscriptionId = DB::table('subscriptions')->insertGetId([
                        'business_id' => $business->id,
                        'plan_id' => $business->plan_id,
                        'status' => 'active',
                        'starts_at' => $now->toDateString(),
                        'expires_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('businesses')->where('id', $business->id)
                        ->update(['current_subscription_id' => $subscriptionId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_subscription_id');
        });
    }
};
