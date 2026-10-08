<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Expiry reminder window
    |--------------------------------------------------------------------------
    |
    | How many days before a subscription's expires_at the
    | subscriptions:expire command sends a "expiring soon" reminder
    | (App\Notifications\SubscriptionExpiringSoon). This is platform
    | configuration only — business owners have no way to change it.
    |
    */

    'expiry_reminder_days' => (int) env('SUBSCRIPTION_EXPIRY_REMINDER_DAYS', 7),

];
