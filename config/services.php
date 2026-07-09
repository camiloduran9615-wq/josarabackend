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

    'dane' => [
        'divipola_url' => env('DANE_DIVIPOLA_URL'),
        'allowed_hosts' => env('DANE_ALLOWED_HOSTS', 'www.dane.gov.co,dane.gov.co,www.datos.gov.co,datos.gov.co'),
        'timeout' => env('DANE_HTTP_TIMEOUT', 20),
        'max_bytes' => env('DANE_MAX_BYTES', 5242880),
    ],

    'factus' => [
        'base_url' => env('FACTUS_BASE_URL', 'https://api-sandbox.factus.com.co'),
        'client_id' => env('FACTUS_CLIENT_ID'),
        'client_secret' => env('FACTUS_CLIENT_SECRET'),
        'username' => env('FACTUS_USERNAME'),
        'password' => env('FACTUS_PASSWORD'),
    ],

];
