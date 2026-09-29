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
        // Purely informational for now — no payment gateway reads this.
        // Default 0 keeps every existing plan valid without edits.
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
