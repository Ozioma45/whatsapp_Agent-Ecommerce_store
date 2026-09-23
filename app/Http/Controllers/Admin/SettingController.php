<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * Show the platform settings form.
     */
    public function edit(): View
    {
        $settings = collect(Setting::SUPPORTED_KEYS)
            ->mapWithKeys(fn (string $key) => [$key => Setting::get($key)]);

        return view('admin.settings.edit', [
            'settings' => $settings,
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the platform settings.
     *
     * Only the known, supported keys are ever written (see
     * Setting::SUPPORTED_KEYS) — an arbitrary key submitted from the
     * browser is simply never looked at.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'platform_name' => ['required', 'string', 'max:255'],
            'platform_description' => ['nullable', 'string', 'max:1000'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_whatsapp' => ['nullable', 'string', 'max:30'],
            'default_plan' => ['required', Rule::exists('plans', 'slug')->where('is_active', true)],
        ]);

        Setting::set(Setting::PLATFORM_NAME, $validated['platform_name']);
        Setting::set(Setting::PLATFORM_DESCRIPTION, $validated['platform_description'] ?? null);
        Setting::set(Setting::SUPPORT_EMAIL, $validated['support_email'] ?? null);
        Setting::set(Setting::SUPPORT_WHATSAPP, $validated['support_whatsapp'] ?? null);
        Setting::set(Setting::DEFAULT_PLAN, $validated['default_plan']);
        Setting::set(Setting::MAINTENANCE_MODE, $request->boolean('maintenance_mode') ? '1' : '0');

        return redirect()->route('admin.settings.edit')->with('status', 'Settings updated.');
    }
}
