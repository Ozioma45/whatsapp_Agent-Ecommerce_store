<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business's connection to the WhatsApp Business Platform. Credentials
 * are encrypted at rest and hidden from array/JSON serialization, so they
 * can never leak into a view, an API response, or an exception report.
 */
#[Fillable(['business_id', 'phone_number_id', 'whatsapp_business_account_id', 'access_token', 'webhook_verify_token', 'status'])]
class WhatsAppIntegrationSetting extends Model
{
    use HasFactory;

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTED = 'connected';

    /**
     * Eloquent's naming convention would split "WhatsApp" into two words
     * ("whats_app_..."); the migration instead names the table the way the
     * rest of the app already spells it (e.g. BusinessSetting's
     * "whatsapp_number" column).
     */
    protected $table = 'whatsapp_integration_settings';

    /**
     * @var array<int, string>
     */
    protected $hidden = ['access_token', 'webhook_verify_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
        ];
    }

    /**
     * The business this integration belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
