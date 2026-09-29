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
        // Safe default: every order created before this phase came through
        // the storefront cart checkout, so backfilling "storefront" keeps
        // existing orders accurate rather than merely non-null.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source')->default('storefront')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
