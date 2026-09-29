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
        // Idempotency for *webhook deliveries* already lives in
        // whatsapp_inbound_messages (Phase 9B) and is left untouched; this
        // table is the actual conversation transcript (both directions),
        // which that table deliberately does not keep.
        Schema::create('whatsapp_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('direction');
            $table->string('message_type')->default('text');
            $table->text('content')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['conversation_id', 'occurred_at']);
            $table->index('whatsapp_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_messages');
    }
};
