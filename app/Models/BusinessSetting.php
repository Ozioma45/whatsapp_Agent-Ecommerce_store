<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'logo', 'description', 'whatsapp_number'])]
class BusinessSetting extends Model
{
    use HasFactory;

    /**
     * The business these settings belong to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
