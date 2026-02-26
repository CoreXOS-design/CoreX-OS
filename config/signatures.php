<?php

return [

    'reminders' => [
        'gentle_after_days' => 2,
        'firm_after_days' => 5,
        'team_alert_after_days' => 7,
        'final_after_days' => 10,
        'max_email_reminders' => 3,
    ],

    'expiry' => [
        'default_days' => 14,
    ],

    'emails' => [
        'company_domain' => env('SIGNATURE_EMAIL_DOMAIN', 'hfcoastal.co.za'),
        'fallback_from' => env('MAIL_FROM_ADDRESS', 'system@hfcoastal.co.za'),
        'from_name' => 'Home Finders Coastal',
    ],

    'leases' => [
        'alert_thresholds' => [90, 60, 30, 0],
        'alert_dedup_days' => 7,
        'default_renewal_years' => 1,
    ],

];
