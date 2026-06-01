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

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
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

    'meta' => [
        'app_id'       => env('META_APP_ID'),
        'app_secret'   => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
    ],

    'anthropic' => [
        // Legacy key — kept for backwards compatibility with existing consumers
        // (AiFieldMapperService, ClaudeVisionParserService, ImporterAiService,
        // MarketingCopyService, AIExtractionService). DO NOT REMOVE without
        // migrating those services to read `api_key` instead.
        'key'     => env('ANTHROPIC_API_KEY'),
        // Canonical name per MIC spec §4.8.
        'api_key' => env('ANTHROPIC_API_KEY'),

        'api_base'      => env('ANTHROPIC_API_BASE', 'https://api.anthropic.com'),
        'default_model' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-haiku-4-5'),
        'models' => [
            'fast'    => env('ANTHROPIC_FAST_MODEL', 'claude-haiku-4-5'),
            'quality' => env('ANTHROPIC_QUALITY_MODEL', 'claude-sonnet-4-6'),
        ],
        'timeout'     => (int) env('ANTHROPIC_TIMEOUT', 30),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 3),
        'enabled'     => filter_var(env('ANTHROPIC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // ZAR conversion — current as of May 2026. Refresh quarterly or when
        // the rate moves > 5%. NB: this is a forward-looking rate only —
        // historical ai_narrative_cache rows are NOT retroactively repriced.
        // Each row's cost_zar is a snapshot at the time of generation.
        'usd_to_zar' => (float) env('ANTHROPIC_USD_TO_ZAR', 16.50),
        // USD per million tokens — refresh when Anthropic changes pricing.
        'pricing' => [
            'claude-haiku-4-5'  => ['input' => 1.00, 'output' => 5.00],
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-opus-4-7'   => ['input' => 5.00, 'output' => 25.00],
        ],
    ],

    'hf_ai' => [
        'base_url'            => env('HF_AI_BASE_URL', 'http://127.0.0.1:3100'),
        'transcribe_timeout'  => env('HF_AI_TRANSCRIBE_TIMEOUT', 15),
        'voice_max_seconds'   => env('AI_VOICE_MAX_SECONDS', 30),
    ],

    'property24_syndication' => [
        'api_url'        => env('P24_EXDEV_API_URL', 'https://api.exdev.property24-test.com'),
        'username'       => env('P24_EXDEV_USERNAME'),
        'password'       => env('P24_EXDEV_PASSWORD'),
        'agency_id'      => env('P24_EXDEV_AGENCY_ID'),
        'sandbox'        => env('P24_EXDEV_SANDBOX', true),
        'image_base_url' => env('P24_EXDEV_IMAGE_BASE_URL', ''),
        'api_version'    => 'v53',
    ],

    'private_property' => [
        'username'       => env('PP_USERNAME'),
        'password'       => env('PP_PASSWORD'),
        'branch_guid'    => env('PP_BRANCH_GUID'),
        'wsdl'           => env('PP_WSDL', 'https://services.sandbox.pp.co.za/AgentImport/AgentImport.asmx?WSDL'),
        'sandbox'        => env('PP_SANDBOX', true),
        'image_base_url' => env('PP_IMAGE_BASE_URL', ''),  // Override APP_URL for image URLs (useful for local dev against sandbox)
        'webhook_secret' => env('PP_WEBHOOK_SECRET'),       // HMAC secret registered in PP Admin Portal
    ],

    'pdf' => [
        'puppeteer_browser_path' => env('PUPPETEER_BROWSER_PATH', ''),
        'node_wrapper'           => env('PDF_NODE_WRAPPER', ''),
    ],

    // Phase 3f — Geocoding waterfall (AddressResolverService).
    // CMA map — Google Static Maps reuses the same Google Cloud project +
    // API key (Maps Static API must be enabled on it). When the static_maps
    // env var isn't set we default to the geocoding key so a single config
    // is the common case; a separate key is supported for agencies that
    // want to split quotas / billing.
    'google' => [
        'geocoding_api_key'   => env('GOOGLE_GEOCODING_API_KEY'),
        'static_maps_api_key' => env('GOOGLE_STATIC_MAPS_API_KEY', env('GOOGLE_GEOCODING_API_KEY')),
    ],

    'nominatim' => [
        'enabled'    => filter_var(env('NOMINATIM_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'user_agent' => env('NOMINATIM_UA', 'CoreXOS/1.0 (admin@corexos.co.za)'),
    ],

];
