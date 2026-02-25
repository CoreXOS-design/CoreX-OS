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

    'portal_fetch' => [
        'url' => env('PORTAL_FETCH_URL', 'http://127.0.0.1:3105'),
    ],

    'p24_imap' => [
        'host' => env('P24_IMAP_HOST'),
        'port' => env('P24_IMAP_PORT', 993),
        'encryption' => env('P24_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('P24_IMAP_USERNAME'),
        'password' => env('P24_IMAP_PASSWORD'),
        'folder' => env('P24_IMAP_FOLDER', 'INBOX'),
        'enabled' => env('P24_IMPORT_ENABLED', false),
    ],

];
