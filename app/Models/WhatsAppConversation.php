<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One customer's ongoing conversation with one business, identified by the
 * business + the customer's WhatsApp number. Always created and queried
 * through a Business's own whatsAppConversations() relation, so a
 * conversation can never be resolved for the wrong business.
 */
#[Fillable(['business_id', 'customer_whatsapp_number'])]
class WhatsAppConversation extends Model
{
    use HasFactory;

    /**
     * See WhatsAppIntegrationSetting::$table for why this is set explicitly.
     */
    protected $table = 'whatsapp_conversations';

    /**
     * The business this conversation belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * This conversation's messages, in both directions.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppConversationMessage::class, 'conversation_id');
    }

    /**
     * This conversation's in-progress order draft, if any.
     */
    public function orderDraft(): HasOne
    {
        return $this->hasOne(WhatsAppOrderDraft::class, 'conversation_id');
    }
}
