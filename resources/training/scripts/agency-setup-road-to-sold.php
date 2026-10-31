<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-road-to-sold',
    'title' => 'Set up the daily call list',
    'url' => '/corex/agency-setup/step/road_to_sold',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up the daily list of properties to phone.',
    'steps' => [
        [
            'selector' => '#f_default_target_1',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many properties an agent works on a Monday.',
            'why' => 'The daily list is measured against it.',
        ],
        [
            'selector' => '#f_default_target_6',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type zero for a day nobody works.',
            'why' => 'Then no list is made for that day.',
        ],
        [
            'selector' => '#f_default_monthly_points',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type the monthly points target for an agent.',
            'why' => 'Managers can see who is on track.',
        ],
        [
            'selector' => '#f_default_monthly_deals',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type the monthly deals target.',
            'why' => 'It shows what a good month looks like.',
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
