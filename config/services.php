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

    'congress_gov' => [
        'api_key' => env('CONGRESS_GOV_API_KEY'),
        'verify_ssl' => env('CONGRESS_GOV_VERIFY_SSL', true),
    ],

    'open_states' => [
        'api_key' => env('OPENSTATES_API_KEY'),
        'max_per_page' => env('OPENSTATES_MAX_PER_PAGE', 20),
        'request_interval_ms' => env('OPENSTATES_REQUEST_INTERVAL_MS', 6500),
        'timeout_seconds' => env('OPENSTATES_TIMEOUT_SECONDS', 60),
        'connect_timeout_seconds' => env('OPENSTATES_CONNECT_TIMEOUT_SECONDS', 15),
        'retry_delay_ms' => env('OPENSTATES_RETRY_DELAY_MS', 1500),
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

];
