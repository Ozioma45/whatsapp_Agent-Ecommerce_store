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
        // One draft per conversation — never an Order. Item prices are
        // deliberately not stored here (see whatsapp_order_draft_items):
        // everything is recomputed from the live Product table whenever
        // the draft is read, so nothing AI- or customer-supplied about a
        // price can ever reach this table, let alone an Order.
        Schema::create('whatsapp_order_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            // null | awaiting_name | awaiting_confirmation
            $table->string('pending_action')->nullable();
            // A snapshot of the items/prices/total last shown to the
            // customer when asked to confirm, so a stale "confirm" reply
            // is detected if anything changed in between (see
            // OrderConversationHandler::confirm()).
            $table->text('confirmation_snapshot')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_order_drafts');
    }
};
