<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-road-to-sold',
    'title' => 'Set up the daily call list',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/road_to_sold',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up the daily list of properties to phone.',
    'steps' => [
        [
            'selector' => '#f_default_target_1',
            'action' => 'type',
            'done' => 'input',
            'value' => '5',
            'narration' => 'Type how many properties an agent works on a Monday.',
            'why' => 'The daily list is measured against it.',
        ],
        [
            'selector' => '#f_default_target_6',
            'action' => 'type',
            'done' => 'input',
            'value' => '0',
            'narration' => 'Type zero for a day nobody works.',
            'why' => 'No list is made for that day.',
        ],
        [
            'selector' => '#f_default_monthly_points',
            'action' => 'type',
            'done' => 'input',
            'value' => '100',
            'narration' => 'Type the monthly points target for an agent.',
            'why' => 'Managers can see who is on track.',
        ],
        [
            'selector' => '#f_default_monthly_deals',
            'action' => 'type',
            'done' => 'input',
            'value' => '2',
            'narration' => 'Type the monthly deals target.',
            'why' => 'It shows what a good month looks like.',
        ],
        [
            'selector' => 'div:has(> label > input[name="lock_agents_to_areas"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if agents stay inside their own areas.',
            'why' => 'Two agents do not chase the same owner.',
        ],
        [
            'selector' => '#f_hold_days',
            'action' => 'type',
            'done' => 'input',
            'value' => '30',
            'narration' => 'Type how many days an agent keeps a property after a call.',
            'why' => 'It gives the agent time to follow up before a colleague can step in.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: your contacts.',
];
