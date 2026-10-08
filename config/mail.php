<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

        // Dedicated mailer for client-auth OTP emails. Sends from
        // Otp@corexos.co.za. SMTP credentials live in .env only.
        // Spec: .ai/specs/client-auth.md
        'otp' => [
            'transport'   => 'smtp',
            'host'        => env('MAIL_OTP_HOST', env('MAIL_HOST', '127.0.0.1')),
            'port'        => env('MAIL_OTP_PORT', env('MAIL_PORT', 587)),
            'encryption'  => env('MAIL_OTP_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls')),
            'username'    => env('MAIL_OTP_USERNAME'),
            'password'    => env('MAIL_OTP_PASSWORD'),
            'timeout'     => null,
            'local_domain'=> env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        // Dedicated mailer for CoreX system/agent notifications (e.g. the
        // client-testimonial alert). Sends from mail@corexos.co.za so these
        // emails deliver via real SMTP regardless of the default mailer — which
        // is intentionally 'log' on staging. Mirrors the 'otp' mailer.
        // `from_address`/`from_name` are read by PillarEventNotification so the
        // From header matches the authenticated SMTP account (avoids SPF/sender
        // rejection). SMTP credentials live in .env only. Spec: testimonials.md §13.6.
        'corex' => [
            'transport'    => 'smtp',
            'host'         => env('MAIL_COREX_HOST', env('MAIL_OTP_HOST', 'mail.corexos.co.za')),
            'port'         => env('MAIL_COREX_PORT', 587),
            'encryption'   => env('MAIL_COREX_ENCRYPTION', 'tls'),
            'username'     => env('MAIL_COREX_USERNAME'),
            'password'     => env('MAIL_COREX_PASSWORD'),
            'timeout'      => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
            'from_address' => env('MAIL_COREX_FROM_ADDRESS', env('MAIL_COREX_USERNAME', 'mail@corexos.co.za')),
            'from_name'    => env('MAIL_COREX_FROM_NAME', 'CoreX OS'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    /*
    | Non-production redirect target for per-mailbox distribution mail
    | (SignedDocumentDistributionService). Outside APP_ENV=production every
    | send is redirected here; when unset, non-production sends are
    | suppressed entirely rather than reaching a real recipient.
    */
    'non_production_redirect' => env('MAIL_NON_PRODUCTION_REDIRECT'),

    /*
    |--------------------------------------------------------------------------
    | Outbound mail guard — local sink (App\Support\OutboundMailGuard)
    |--------------------------------------------------------------------------
    | Read through config() on purpose: app code calling env() directly gets null
    | once `config:cache` is on (it is, on Staging/QA), which silently changed
    | the sink and would have hidden a real MAIL_HOST from the boot-time check.
    */
    'guard' => [
        // The ONE server-side flag that says "this is the real live server". Set by hand in the live .env
        // (OUTBOUND_MAIL_REAL_SEND=1), absent everywhere else - a copy of live's code, database or even its
        // APP_ENV/APP_URL cannot carry it by accident. Without it nothing sends, whatever APP_ENV says.
        'real_send' => filter_var(env('OUTBOUND_MAIL_REAL_SEND', false), FILTER_VALIDATE_BOOLEAN),
        'sink_host' => env('MAIL_GUARD_SINK_HOST', env('MAIL_HOST', '127.0.0.1')),
        'sink_port' => env('MAIL_GUARD_SINK_PORT', env('MAIL_PORT', 1025)),
        'sink_address' => env('MAIL_GUARD_SINK_ADDRESS', 'outbound-guard@localhost.test'),
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
