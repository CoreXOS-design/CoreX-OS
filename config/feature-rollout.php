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

    // Follow-up after a feature switches on (spec §12.6–12.8). Platform-wide product tuning, the same for
    // every agency: how long before someone who has not taken a feature's guided tour is reminded (once);
    // when the CoreX team / the admin is told a group is hardly being used (suggestion only, never automatic);
    // and when a paused plan is flagged on the CoreX team's overview.
    'follow_up' => [
        'tour_nudge_after_days' => 3,
        'tour_nudge_window_days' => 30,   // never remind about something that switched on longer ago than this
        'low_use_after_days'    => 2,     // give a group this long before judging how much it is used
        'low_use_min_people'    => 3,     // too few people with access to judge
        'low_use_max_share'     => 0.25,  // under a quarter of the people with access have opened it
        'paused_flag_days'      => 7,
    ],

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
