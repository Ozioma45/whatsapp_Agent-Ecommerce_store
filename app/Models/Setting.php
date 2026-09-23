<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single platform-wide key/value setting (platform name, support email,
 * default plan, maintenance mode, ...). Values are always stored as plain
 * strings; callers cast as needed (see get()/set() below).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use HasFactory;

    public const PLATFORM_NAME = 'platform_name';

    public const PLATFORM_DESCRIPTION = 'platform_description';

    public const SUPPORT_EMAIL = 'support_email';

    public const SUPPORT_WHATSAPP = 'support_whatsapp';

    public const DEFAULT_PLAN = 'default_plan';

    public const MAINTENANCE_MODE = 'maintenance_mode';

    /**
     * The keys the settings admin page is allowed to write — nothing else
     * is ever accepted from a submitted form (see Admin\SettingController).
     *
     * @var array<int, string>
     */
    public const SUPPORTED_KEYS = [
        self::PLATFORM_NAME,
        self::PLATFORM_DESCRIPTION,
        self::SUPPORT_EMAIL,
        self::SUPPORT_WHATSAPP,
        self::DEFAULT_PLAN,
        self::MAINTENANCE_MODE,
    ];

    /**
     * Read a setting's value, or $default when it isn't set.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    /**
     * Write a setting's value (creating it if it doesn't exist yet).
     */
    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Read a boolean-ish setting ("1"/"0", "true"/"false", ...).
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = static::get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
