<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    /**
     * List the authenticated user's own business products.
     */
    public function index(Request $request): View
    {
        $products = $request->user()->business
            ->products()
            ->with('category')
            ->orderBy('name')
            ->get();

        return view('products.index', ['products' => $products]);
    }

    /**
     * Show the form to create a new product.
     */
    public function create(Request $request): View
    {
        $categories = $request->user()->business->categories()->orderBy('name')->get();

        return view('products.create', ['categories' => $categories]);
    }

    /**
     * Store a new product for the authenticated user's own business.
     */
    public function store(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        $validated = $request->validate($this->rules($business));

        $validated['is_available'] = $request->boolean('is_available');

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        $business->products()->create($validated);

        return redirect()->route('products.index')->with('status', 'Product created.');
    }

    /**
     * Show the form to edit one of the authenticated user's own products.
     *
     * The product is always looked up scoped to the authenticated user's
     * own business, so an id belonging to another business simply 404s.
     */
    public function edit(Request $request, string $product): View
    {
        $business = $request->user()->business;
        $product = $business->products()->findOrFail($product);
        $categories = $business->categories()->orderBy('name')->get();

        return view('products.edit', ['product' => $product, 'categories' => $categories]);
    }

    /**
     * Update one of the authenticated user's own products.
     */
    public function update(Request $request, string $product): RedirectResponse
    {
        $business = $request->user()->business;
        $product = $business->products()->findOrFail($product);

        $validated = $request->validate($this->rules($business));

        $validated['is_available'] = $request->boolean('is_available');

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        $product->update($validated);

        return redirect()->route('products.index')->with('status', 'Product updated.');
    }

    /**
     * Delete one of the authenticated user's own products, and its stored image.
     */
    public function destroy(Request $request, string $product): RedirectResponse
    {
        $product = $request->user()->business->products()->findOrFail($product);

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return redirect()->route('products.index')->with('status', 'Product deleted.');
    }

    /**
     * Validation rules shared by store and update.
     *
     * The category, if any, must belong to the same business — never trust
     * a category id supplied by the form.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(Business $business): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('business_id', $business->id),
            ],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }
}
