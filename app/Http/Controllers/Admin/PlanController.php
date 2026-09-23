<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanController extends Controller
{
    /**
     * List the platform's plans.
     */
    public function index(): View
    {
        return view('admin.plans.index', [
            'plans' => Plan::withCount('businesses')->orderBy('id')->get(),
        ]);
    }

    /**
     * Show one plan's details and its feature entitlements.
     */
    public function show(string $plan): View
    {
        $plan = Plan::with('features')->findOrFail($plan);

        return view('admin.plans.show', ['plan' => $plan]);
    }

    /**
     * Update a plan's own fields and its feature entitlements.
     *
     * This reuses the existing Phase 8 plan_features pivot exclusively —
     * it never creates a feature, and it only ever writes to pivot rows
     * that already belong to this plan (from the database, not from
     * whatever the form happened to submit), so an arbitrary feature id
     * can never be attached.
     */
    public function update(Request $request, string $plan): RedirectResponse
    {
        $plan = Plan::with('features')->findOrFail($plan);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        foreach ($plan->features as $feature) {
            $limitInput = $request->input("features.{$feature->id}.limit");
            $limit = ($limitInput === null || $limitInput === '') ? null : max(0, (int) $limitInput);

            $plan->features()->updateExistingPivot($feature->id, [
                'enabled' => $request->boolean("features.{$feature->id}.enabled"),
                'limit' => $limit,
            ]);
        }

        return redirect()->route('admin.plans.show', $plan)->with('status', 'Plan updated.');
    }

    /**
     * Delete a plan — only ever allowed when no business currently uses
     * it. In practice, toggling a plan inactive (see update()) is the
     * preferred way to retire one; this exists as a safety-checked escape
     * hatch, not a routine action.
     */
    public function destroy(string $plan): RedirectResponse
    {
        $plan = Plan::findOrFail($plan);

        if ($plan->businesses()->exists()) {
            return redirect()->route('admin.plans.show', $plan)
                ->with('error', 'This plan cannot be deleted while businesses are assigned to it. Mark it inactive instead.');
        }

        $plan->delete();

        return redirect()->route('admin.plans.index')->with('status', 'Plan deleted.');
    }
}
