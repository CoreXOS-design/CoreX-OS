<?php

/**
 * Gradual feature rollout for new agencies (AT-478). Spec: .ai/specs/agency-feature-rollout.md.
 *
 * The default ORDER of waves is not here — it is edited in Dev Settings → Feature rollout
 * (feature_rollout_waves). This file holds only fixed rules and the fallback pacing used when
 * Dev Settings has never been saved.
 */
return [

    // Features that reach people OUTSIDE the agency. A wave never switches these on by itself: they
    // wait for the agency admin to click them on (spec §2.9). Portal syndication and payroll are not
    // listed because they start off for every new agency anyway (config/agency-types.php) and so are
    // never held by a rollout.
    'admin_switch_on' => [
        'public-website',
        'outreach',
    ],

    // Fallback pacing (Dev Settings keys feature_rollout.* override these).
    'defaults' => [
        'interval_days'   => 2,
        'weekdays_only'   => true,
        'skip_holidays'   => true,
        'holiday_country' => 'ZA',
        'release_time'    => '07:00',
    ],

    // "How often" choices offered in Dev Settings and agency setup (value = release days between waves).
    'interval_choices' => [
        1 => 'Every day',
        2 => 'Every 2 days',
        3 => 'Every 3 days',
        5 => 'Every week (5 release days)',
    ],
    'interval_max' => 10,

    // Readiness (spec §12.1): what an agency must have filled in before a feature is worth switching on.
    // A feature whose basics are missing waits as "Set up first" when its wave is released, and switches
    // on by itself once they are filled in (the admin can also switch it on anyway). Each requirement is
    // an `agencies` column that must not be blank, the plain words for it, and the setup-guide step
    // (config/agency-onboarding-copy.php key) where the agency fills it in. Same for every agency.
    'readiness' => [
        'docuperfect' => [
            ['field' => 'logo_path', 'label' => 'your logo',             'step' => 'branding'],
            ['field' => 'address',   'label' => 'your physical address', 'step' => 'identity'],
        ],
        'compliance' => [
            ['field' => 'ffc_no',      'label' => "your agency's Fidelity Fund Certificate number", 'step' => 'identity'],
            ['field' => 'ppra_number', 'label' => 'your PPRA reference number',                    'step' => 'identity'],
        ],
    ],
];
