<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'portal' => [
        'url'              => env('PORTAL_URL'),
        'email'            => env('PORTAL_EMAIL'),
        'password'         => env('PORTAL_PASSWORD'),
        // Comma-separated keywords that indicate a successful portal submission.
        // Override PORTAL_SUCCESS_KEYWORDS in .env if the portal UI text changes.
        'success_keywords' => env('PORTAL_SUCCESS_KEYWORDS', 'success,saved successfully,record added,created successfully,activity logged,data saved'),
    ],

    'cron' => [
        'token' => env('CRON_TOKEN'),
    ],

    'sync' => [
        'max_slots' => (int) env('SYNC_MAX_SLOTS', 8),
        'shared_dir' => env('SYNC_SHARED_DIR', '/home/u335000182/shared_sync'),
    ],

];
