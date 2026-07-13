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

    'whatsapp' => [
        'enabled' => env('WHATSAPP_API_ENABLED', true),
        'endpoint' => env('WHATSAPP_API_ENDPOINT'),
        'token' => env('WHATSAPP_API_TOKEN'),
        'device_id' => env('WHATSAPP_API_DEVICE_ID'),
        'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),
        'rate_limit_per_minute' => env('WHATSAPP_RATE_LIMIT_PER_MINUTE', 5),
        'rate_limit_key' => env('WHATSAPP_RATE_LIMIT_KEY', 'global'),
        'timeout' => env('WHATSAPP_API_TIMEOUT', 10),
        'otp_ttl_minutes' => env('WHATSAPP_OTP_TTL_MINUTES', 5),
        'otp_rate_limit_max' => env('WHATSAPP_OTP_RATE_LIMIT_MAX', 3),
        'otp_rate_limit_minutes' => env('WHATSAPP_OTP_RATE_LIMIT_MINUTES', 1),
    ],

    'contract_outstanding' => [
        'base_url' => env('CONTRACT_OUTSTANDING_BASE_URL'),
        'endpoint' => env('CONTRACT_OUTSTANDING_ENDPOINT', '/api/slik/inquiry'),
        'token' => env('CONTRACT_OUTSTANDING_TOKEN'),
        'timeout' => (int) env('CONTRACT_OUTSTANDING_TIMEOUT', 30),
        'retry_times' => (int) env('CONTRACT_OUTSTANDING_RETRY_TIMES', 0),
        'retry_sleep_ms' => (int) env('CONTRACT_OUTSTANDING_RETRY_SLEEP_MS', 250),
    ],

];
