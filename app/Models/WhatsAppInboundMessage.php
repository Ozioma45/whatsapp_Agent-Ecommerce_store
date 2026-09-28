<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A minimal record of one inbound WhatsApp webhook message, kept only so a
 * retried delivery (same whatsapp_message_id) is never processed twice.
 * Deliberately not a conversation store: no message body is kept here.
 */
#[Fillable(['business_id', 'whatsapp_message_id', 'message_type', 'received_at'])]
class WhatsAppInboundMessage extends Model
{
    use HasFactory;

    /**
     * See WhatsAppIntegrationSetting::$table for why this is set explicitly.
     */
    protected $table = 'whatsapp_inbound_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    /**
     * The business this message was sent to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
