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
        // 'company_domain' removed 2026-08-12 — was a single hardcoded domain
        // for every tenant (multi-tenancy bug). BaseSignatureMail now derives
        // each agent's company domain from their OWN agency's email instead
        // (App\Mail\Signatures\BaseSignatureMail::companyDomainForAgent()).
        'fallback_from' => env('MAIL_FROM_ADDRESS', 'mail@corexos.co.za'),
        'from_name' => 'CoreX OS',
    ],

    'leases' => [
        'alert_thresholds' => [90, 60, 30, 0],
        'alert_dedup_days' => 7,
        'default_renewal_years' => 1,
    ],

];
