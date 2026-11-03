<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
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
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up the areas and prices Market Intelligence follows.',
    'steps' => [
        [
            'selector' => '#f_listing_off_market_days',
            'action' => 'type',
            'done' => 'input',
            'value' => '90',
            'narration' => 'Type how many days unseen before an advert counts as off the market.',
            'why' => 'Old adverts then drop off your lists.',
        ],
        [
            'selector' => 'div:has(> label > input[name="report_ai_read_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if CoreX may read market reports it does not know.',
            'why' => 'The report is filed with its figures, not left unread.',
        ],
        [
            'selector' => 'input[name="region"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Add the towns you work in. Add their suburbs underneath.',
            'why' => 'Market Intelligence only follows the areas you list, so your lists stay relevant.',
        ],
        [
            'selector' => 'input[name="beds_min"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Add your bedroom bands, like 2 bedrooms or 3 and more.',
            'why' => 'Agents and buyers filter by these bands.',
        ],
        [
            'selector' => 'input[name="price_min"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Add your price bands, for sales and for rentals.',
            'why' => 'Prices are grouped the way you talk about them.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: the daily call list.',
];
