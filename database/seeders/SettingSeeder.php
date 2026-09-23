<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds the initial platform settings. Safe to re-run: every write is an
 * updateOrCreate keyed on the setting's unique key, so re-seeding never
 * overwrites a value an admin has already changed via /admin/settings.
 */
class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaults = [
            Setting::PLATFORM_NAME => config('app.name'),
            Setting::PLATFORM_DESCRIPTION => 'Simple online stores with WhatsApp ordering.',
            Setting::SUPPORT_EMAIL => null,
            Setting::SUPPORT_WHATSAPP => null,
            Setting::DEFAULT_PLAN => Plan::STANDARD,
            Setting::MAINTENANCE_MODE => '0',
        ];

        foreach ($defaults as $key => $value) {
            // updateOrCreate would overwrite an existing value with the
            // default on every re-run, so only ever create a setting that
            // doesn't exist yet.
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
