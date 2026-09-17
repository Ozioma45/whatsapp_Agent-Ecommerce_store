<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line in an order.
 *
 * product_name and unit_price are copied in at order-creation time and
 * never re-read from the Product afterward — this is deliberate, so a
 * later product rename, repricing, or deletion never alters historical
 * order data (see OrderController and CartController::checkout()).
 */
#[Fillable(['order_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'subtotal'])]
class OrderItem extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    /**
     * The order this item belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The product this item was ordered from, if it still exists.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
