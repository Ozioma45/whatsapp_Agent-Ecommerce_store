<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a draft order: a product id and a quantity, nothing else.
 * No price is stored here on purpose — see WhatsAppOrderDraft.
 */
#[Fillable(['draft_id', 'product_id', 'quantity'])]
class WhatsAppOrderDraftItem extends Model
{
    use HasFactory;

    /**
     * See WhatsAppIntegrationSetting::$table for why this is set explicitly.
     */
    protected $table = 'whatsapp_order_draft_items';

    /**
     * The draft this item belongs to.
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(WhatsAppOrderDraft::class, 'draft_id');
    }

    /**
     * The product this item refers to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
