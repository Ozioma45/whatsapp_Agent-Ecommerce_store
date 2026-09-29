<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in one conversation, in either direction. Holds the message
 * content itself (unlike WhatsAppInboundMessage, which deliberately does
 * not) because the conversation engine needs real history to build context
 * from — but never anything beyond that: no tokens, no secrets.
 */
#[Fillable(['conversation_id', 'direction', 'message_type', 'content', 'whatsapp_message_id', 'occurred_at'])]
class WhatsAppConversationMessage extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    /**
     * See WhatsAppIntegrationSetting::$table for why this is set explicitly.
     */
    protected $table = 'whatsapp_conversation_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * The conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }
}
