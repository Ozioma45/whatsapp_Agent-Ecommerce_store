<?php

namespace App\Support\Ai;

use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * The AI-visible view of one business's catalogue. Built for exactly one
 * business and only ever queries through that business's own
 * relationships, so it cannot return another business's data. Reads the
 * existing Product/Category tables — there is no separate AI copy — so
 * prices and availability are always the current database values.
 */
class ProductCatalogService
{
    public function __construct(private readonly Business $business) {}

    /**
     * The business's available products only.
     *
     * @return Collection<int, array{name: string, description: ?string, price: string, available: bool, category: ?string}>
     */
    public function availableProducts(): Collection
    {
        return $this->business->products()
            ->where('is_available', true)
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(fn ($product) => [
                'name' => $product->name,
                'description' => $product->description,
                'price' => number_format((float) $product->price, 2, '.', ''),
                'available' => true,
                'category' => $product->category?->name,
            ]);
    }

    /**
     * The business's category names.
     *
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return $this->business->categories()->orderBy('name')->pluck('name');
    }
}
