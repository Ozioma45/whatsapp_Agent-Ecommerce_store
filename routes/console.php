<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Subscription lifecycle sweep (Phase 10C) — expires lapsed subscriptions,
// promotes scheduled downgrades, and sends expiry reminders. Idempotent,
// so overlapping/duplicate runs are safe; see the command's own docblock.
Schedule::command('subscriptions:expire')->daily();
