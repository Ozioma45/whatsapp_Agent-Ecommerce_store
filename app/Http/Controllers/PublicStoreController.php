<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicStoreController extends Controller
{
    /**
     * Show a business's public store.
     *
     * The business is resolved entirely from the subdomain via route-model
     * binding on its handle (see Business::getRouteKeyName()) — a visitor
     * can never reach another business's store by manipulating an id or
     * query parameter, and an unknown handle results in a 404 automatically.
     */
    public function show(Request $request, Business $business): View
    {
        $business->loadMissing('setting');

        $categories = $business->categories()->orderBy('name')->get();

        // The selected category, if any, is only ever looked up within this
        // business's own categories — an id for another business's category
        // simply matches nothing and the filter is silently ignored.
        $selectedCategory = $categories->firstWhere('id', (int) $request->query('category'));

        $products = $business->products()
            ->where('is_available', true)
            ->when($selectedCategory, fn ($query) => $query->where('category_id', $selectedCategory->id))
            ->with('category')
            ->orderBy('name')
            ->get();

        return view('store.show', [
            'business' => $business,
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'products' => $products,
        ]);
    }
}
