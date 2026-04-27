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
        'request_interval_ms' => env('CONGRESS_GOV_REQUEST_INTERVAL_MS', 250),
        'timeout_seconds' => env('CONGRESS_GOV_TIMEOUT_SECONDS', 30),
        'rate_limit_cooldown_seconds' => env('CONGRESS_GOV_RATE_LIMIT_COOLDOWN_SECONDS', 300),
    ],

    'open_states' => [
        'api_key' => env('OPENSTATES_API_KEY'),
        'max_per_page' => env('OPENSTATES_MAX_PER_PAGE', 20),
        'request_interval_ms' => env('OPENSTATES_REQUEST_INTERVAL_MS', 6500),
        'timeout_seconds' => env('OPENSTATES_TIMEOUT_SECONDS', 60),
        'connect_timeout_seconds' => env('OPENSTATES_CONNECT_TIMEOUT_SECONDS', 15),
        'retry_delay_ms' => env('OPENSTATES_RETRY_DELAY_MS', 1500),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'bill_model' => env('OPENAI_BILL_MODEL', 'gpt-5.4-mini'),
        'timeout_seconds' => env('OPENAI_TIMEOUT_SECONDS', 45),
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'district_population' => [
        'provider' => env('DISTRICT_POPULATION_PROVIDER', 'manual'),
        'api_key' => env('DISTRICT_POPULATION_API_KEY'),
        'base_url' => env('DISTRICT_POPULATION_BASE_URL'),
        'static_federal_voters' => (int) env('DISTRICT_POPULATION_STATIC_FEDERAL_VOTERS', 750000),
        'static_state_voters' => (int) env('DISTRICT_POPULATION_STATIC_STATE_VOTERS', 250000),
        'static_default_voters' => (int) env('DISTRICT_POPULATION_STATIC_DEFAULT_VOTERS', 500000),
    ],

    'identity_verification' => [
        'provider' => env('IDENTITY_VERIFICATION_PROVIDER', 'manual'),
        'persona_api_key' => env('PERSONA_API_KEY'),
        'idenfy_api_key' => env('IDENFY_API_KEY'),
        'veryfi_client_id' => env('VERYFI_CLIENT_ID'),
        'veryfi_api_key' => env('VERYFI_API_KEY'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', 'storage/app/private/firebase/service-account.json'),
        'timeout_seconds' => env('FIREBASE_TIMEOUT_SECONDS', 15),
    ],

];
