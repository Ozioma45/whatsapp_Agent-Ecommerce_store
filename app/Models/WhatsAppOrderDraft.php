<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation's in-progress order — never an Order. Deliberately
 * carries no product name/price of its own (see WhatsAppOrderDraftItem);
 * everything is resolved fresh from the Product table by OrderDraftStore
 * implementations, so nothing AI- or customer-supplied about a price can
 * ever reach this table.
 */
#[Fillable(['conversation_id', 'customer_name', 'customer_phone', 'pending_action', 'confirmation_snapshot'])]
class WhatsAppOrderDraft extends Model
{
    use HasFactory;

    public const PENDING_NAME = 'awaiting_name';

    public const PENDING_CONFIRMATION = 'awaiting_confirmation';

    /**
     * See WhatsAppIntegrationSetting::$table for why this is set explicitly.
     */
    protected $table = 'whatsapp_order_drafts';

    /**
     * The conversation this draft belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    /**
     * This draft's line items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(WhatsAppOrderDraftItem::class, 'draft_id');
    }
}
