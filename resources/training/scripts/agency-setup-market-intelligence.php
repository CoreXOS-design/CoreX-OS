<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-market-intelligence',
    'title' => 'Set up Market Intelligence',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/market_intelligence',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up Market Intelligence.',
    'steps' => [
        [
            'selector' => '#f_listing_off_market_days',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many days unseen before an advert counts as off the market.',
            'why' => 'Old adverts then drop off your lists.',
        ],
        [
            'selector' => 'label:has(#f_report_ai_read_enabled)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if CoreX may read market reports it does not know.',
            'why' => 'The report is filed with its figures, not left unread.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'Good. Carry on to the next step when you are ready.',
];
