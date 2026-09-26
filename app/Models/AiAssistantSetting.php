<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business's own AI assistant configuration. Whether a business may use
 * the assistant at all is decided by its plan (Business::hasFeature());
 * this only records whether an eligible business has chosen to turn it on.
 */
#[Fillable(['business_id', 'enabled', 'welcome_message', 'business_instructions', 'tone'])]
class AiAssistantSetting extends Model
{
    use HasFactory;

    public const DEFAULT_WELCOME_MESSAGE = 'Hello! How can I help you today?';

    public const TONE_FRIENDLY = 'friendly';

    public const TONE_PROFESSIONAL = 'professional';

    public const TONE_CONCISE = 'concise';

    /**
     * The only tones a business can choose from.
     *
     * @var array<int, string>
     */
    public const TONES = [self::TONE_FRIENDLY, self::TONE_PROFESSIONAL, self::TONE_CONCISE];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'welcome_message' => self::DEFAULT_WELCOME_MESSAGE,
            'tone' => self::TONE_FRIENDLY,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
