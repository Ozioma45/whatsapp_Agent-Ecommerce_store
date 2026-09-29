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
        // One row per (business, customer) pair — the unit conversation
        // history is grouped by. Never shared across businesses: every
        // query into this table goes through a business's own relation.
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('customer_whatsapp_number');
            $table->timestamps();

            $table->unique(['business_id', 'customer_whatsapp_number'], 'whatsapp_conversations_business_customer_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
