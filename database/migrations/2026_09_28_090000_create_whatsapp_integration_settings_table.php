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
        Schema::create('whatsapp_integration_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_number_id')->nullable()->unique();
            $table->string('whatsapp_business_account_id')->nullable();
            // access_token and webhook_verify_token are stored via Eloquent's
            // "encrypted" cast (see WhatsAppIntegrationSetting), so these are
            // plain text columns holding ciphertext, never the raw secret.
            $table->text('access_token')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->string('status')->default('disconnected');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_integration_settings');
    }
};
