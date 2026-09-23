<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BusinessController extends Controller
{
    /**
     * List every business on the platform, with server-side search.
     *
     * Deliberately platform-wide (not scoped to the admin's own business —
     * an admin need not own one at all): access is restricted entirely by
     * the "admin" middleware on this route group.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $businesses = Business::query()
            ->with(['owner', 'plan'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('handle', 'like', "%{$search}%")
                        ->orWhereHas('owner', fn ($owner) => $owner->where('email', 'like', "%{$search}%"));
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.businesses.index', ['businesses' => $businesses, 'search' => $search]);
    }

    /**
     * Show one business's full detail — looked up by handle (like the
     * public storefront) rather than id, since Business's route key is
     * already the handle (see Business::getRouteKeyName()).
     */
    public function show(string $business): View
    {
        $business = Business::with(['owner', 'setting', 'plan'])->where('handle', $business)->firstOrFail();

        return view('admin.businesses.show', [
            'business' => $business,
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(),
            'productCount' => $business->products()->count(),
            'categoryCount' => $business->categories()->count(),
            'orderCount' => $business->orders()->count(),
        ]);
    }

    /**
     * Manually assign a business to a different plan.
     *
     * Only an existing, active plan id can ever be assigned — never an
     * arbitrary id supplied by the form. No billing is involved; this is
     * a direct admin override (see Phase 8's PlanFeatureService, which
     * every entitlement check reads from automatically once the plan_id
     * changes).
     */
    public function updatePlan(Request $request, string $business): RedirectResponse
    {
        $business = Business::where('handle', $business)->firstOrFail();

        $validated = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        $business->update(['plan_id' => $validated['plan_id']]);

        return redirect()->route('admin.businesses.show', $business)->with('status', 'Plan updated.');
    }
}
