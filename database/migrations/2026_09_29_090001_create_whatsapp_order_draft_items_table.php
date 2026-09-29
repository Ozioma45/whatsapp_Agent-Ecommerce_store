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
        Schema::create('whatsapp_order_draft_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('whatsapp_order_drafts')->cascadeOnDelete();
            // Cascade (not set-null, unlike order_items): a draft item has
            // no historical significance once its product is gone — it
            // simply disappears from the draft, which is exactly the
            // "handle deleted products" safety behaviour this phase asks for.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['draft_id', 'product_id'], 'whatsapp_order_draft_items_draft_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_order_draft_items');
    }
};
