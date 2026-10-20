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
];
