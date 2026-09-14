<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    /**
     * List the authenticated user's own business categories.
     */
    public function index(Request $request): View
    {
        $categories = $request->user()->business
            ->categories()
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return view('categories.index', ['categories' => $categories]);
    }

    /**
     * Show the form to create a new category.
     */
    public function create(): View
    {
        return view('categories.create');
    }

    /**
     * Store a new category for the authenticated user's own business.
     */
    public function store(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories')->where('business_id', $business->id),
            ],
        ]);

        $business->categories()->create($validated);

        return redirect()->route('categories.index')->with('status', 'Category created.');
    }

    /**
     * Show the form to edit one of the authenticated user's own categories.
     *
     * The category is always looked up scoped to the authenticated user's
     * own business, so an id belonging to another business simply 404s.
     */
    public function edit(Request $request, string $category): View
    {
        $category = $request->user()->business->categories()->findOrFail($category);

        return view('categories.edit', ['category' => $category]);
    }

    /**
     * Update one of the authenticated user's own categories.
     */
    public function update(Request $request, string $category): RedirectResponse
    {
        $business = $request->user()->business;
        $category = $business->categories()->findOrFail($category);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories')->where('business_id', $business->id)->ignore($category->id),
            ],
        ]);

        $category->update($validated);

        return redirect()->route('categories.index')->with('status', 'Category updated.');
    }

    /**
     * Delete one of the authenticated user's own categories.
     *
     * Products in this category are not deleted — the categories table's
     * category_id foreign key is set to null on delete (see migration).
     */
    public function destroy(Request $request, string $category): RedirectResponse
    {
        $category = $request->user()->business->categories()->findOrFail($category);

        $category->delete();

        return redirect()->route('categories.index')->with('status', 'Category deleted.');
    }
}
