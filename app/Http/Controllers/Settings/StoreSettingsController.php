<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StoreSettingsController extends Controller
{
    /**
     * Show the store settings form for the authenticated user's own business.
     */
    public function edit(Request $request): View
    {
        $business = $request->user()->business()->with('setting')->firstOrFail();

        return view('settings.edit', ['business' => $business]);
    }

    /**
     * Update the authenticated user's own business and its settings.
     *
     * The business is always resolved from the authenticated user, so a
     * business owner can never update another business's data regardless
     * of what is submitted in the request.
     */
    public function update(Request $request): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        $business->update(['name' => $validated['name']]);

        $setting = $business->setting()->firstOrCreate();

        if ($request->hasFile('logo')) {
            if ($setting->logo) {
                Storage::disk('public')->delete($setting->logo);
            }

            $validated['logo'] = $request->file('logo')->store('logos', 'public');
        }

        $setting->update([
            'description' => $validated['description'] ?? null,
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'logo' => $validated['logo'] ?? $setting->logo,
        ]);

        return redirect()->route('settings.edit')->with('status', 'Store settings updated.');
    }
}
