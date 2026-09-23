<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['key', 'name', 'description', 'type'])]
class Feature extends Model
{
    use HasFactory;

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_LIMIT = 'limit';

    /**
     * Feature keys, so call sites never need to spell out a magic string.
     * The actual entitlement logic still lives centrally in
     * PlanFeatureService — these are just typo-safe references to it.
     */
    public const STOREFRONT = 'storefront';

    public const WHATSAPP_ORDERING = 'whatsapp_ordering';

    public const ORDER_MANAGEMENT = 'order_management';

    public const PRODUCTS_LIMIT = 'products_limit';

    public const CATEGORIES = 'categories';

    public const CUSTOM_BRANDING = 'custom_branding';

    public const AI_ASSISTANT = 'ai_assistant';

    /**
     * The plans this feature is configured for.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features')
            ->withPivot(['enabled', 'limit'])
            ->withTimestamps();
    }
}
