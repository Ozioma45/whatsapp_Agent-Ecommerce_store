<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business Platform (Meta)
    |--------------------------------------------------------------------------
    |
    | These are platform-level (one Meta App for the whole SaaS), not
    | per-business — per-business credentials live in the
    | whatsapp_integration_settings table instead. The app secret verifies
    | inbound webhook signatures; the verify token answers Meta's one-time
    | webhook verification challenge.
    |
    */

    'whatsapp' => [
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paystack
    |--------------------------------------------------------------------------
    |
    | The secret key authenticates server-to-server calls (initialize,
    | verify) and must never reach a view or frontend script. The public
    | key is safe to expose but isn't currently used, since checkout is a
    | server-initiated redirect to Paystack's hosted page, not inline.js.
    |
    */

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    ],

];
