<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable at the database level so this migration is safe to run
     * against businesses that already exist — PlanSeeder backfills every
     * business to the Standard plan, and new businesses are always
     * assigned a plan at registration (see RegisteredUserController), so
     * plan_id is guaranteed non-null in practice.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
