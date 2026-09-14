<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'handle', 'owner_id'])]
class Business extends Model
{
    use HasFactory;

    /**
     * Handles that could conflict with the main application or future
     * system routes, and so can never be assigned to a business.
     *
     * @var array<int, string>
     */
    public const RESERVED_HANDLES = [
        'www', 'app', 'admin', 'api', 'dashboard', 'login', 'register', 'support', 'help',
    ];

    /**
     * The user who owns this business.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The store settings belonging to this business.
     */
    public function setting(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }

    /**
     * Use the handle (not the id) when this model is resolved from a route,
     * so public store URLs and route-model binding work off the handle.
     */
    public function getRouteKeyName(): string
    {
        return 'handle';
    }

    /**
     * The public store URL for this business, built from its handle and
     * the platform's configured base domain.
     */
    public function publicUrl(): string
    {
        return route('store.show', ['business' => $this->handle]);
    }

    /**
     * Generate a unique, URL/subdomain-safe handle from a business name,
     * appending "-2", "-3", etc. when the base handle is already taken or
     * reserved for platform use.
     */
    public static function generateUniqueHandle(string $name): string
    {
        $base = Str::slug($name);
        $handle = $base;
        $suffix = 2;

        while (in_array($handle, self::RESERVED_HANDLES, true) || static::where('handle', $handle)->exists()) {
            $handle = "{$base}-{$suffix}";
            $suffix++;
        }

        return $handle;
    }
}
