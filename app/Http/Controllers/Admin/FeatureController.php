<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeatureController extends Controller
{
    /**
     * List the platform's features. Features are primarily managed
     * through plan configuration (see Admin\PlanController) — this page
     * only allows safely editing a feature's display copy.
     */
    public function index(): View
    {
        return view('admin.features.index', [
            'features' => Feature::orderBy('name')->get(),
        ]);
    }

    /**
     * Update a feature's display name/description only.
     *
     * Deliberately never accepts "key" or "type" — application code
     * (PlanFeatureService, the Feature::* constants) depends on the key
     * staying stable, and changing a feature's type would silently
     * invalidate its existing plan_features rows.
     */
    public function update(Request $request, string $feature): RedirectResponse
    {
        $feature = Feature::findOrFail($feature);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $feature->update($validated);

        return redirect()->route('admin.features.index')->with('status', 'Feature updated.');
    }
}
