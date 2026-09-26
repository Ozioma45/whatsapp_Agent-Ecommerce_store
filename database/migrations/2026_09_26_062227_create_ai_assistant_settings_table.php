<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Also backfills a default (disabled) settings row for every business
     * that already exists, so no business is left without one. New
     * businesses get theirs automatically (see Business::booted()).
     */
    public function up(): void
    {
        Schema::create('ai_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->text('welcome_message')->nullable();
            $table->text('business_instructions')->nullable();
            $table->string('tone')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('businesses')->pluck('id')->each(function (int $businessId) use ($now) {
            DB::table('ai_assistant_settings')->insert([
                'business_id' => $businessId,
                'enabled' => false,
                'welcome_message' => 'Hello! How can I help you today?',
                'tone' => 'friendly',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_settings');
    }
};
