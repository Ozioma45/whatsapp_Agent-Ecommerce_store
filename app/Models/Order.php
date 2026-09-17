<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'order_number', 'customer_name', 'customer_phone', 'status', 'subtotal', 'total'])]
class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The only statuses an order may have.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * The business this order was placed with.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The line items in this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * A human-readable label for the current status.
     */
    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }

    /**
     * Generate a unique, human-readable order number, e.g. "ORD-20260917-0001".
     * Never a raw database id — this is the customer-facing reference.
     */
    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $sequence = 1;

        do {
            $orderNumber = sprintf('ORD-%s-%04d', $date, $sequence);
            $sequence++;
        } while (static::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
