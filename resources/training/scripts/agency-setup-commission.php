<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-commission',
    'title' => 'Set up agent pay',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/commission',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up how your agents are paid.',
    'steps' => [
        [
            'selector' => 'input[name="commission_split_agent"]',
            'action' => 'type',
            'done' => 'input',
            'value' => '70',
            'narration' => 'Type the share of each commission that goes to the agent, as a percent.',
            'why' => 'This is the starting point for every agent\'s pay.',
        ],
        [
            'selector' => 'input[name="annual_cap"]',
            'action' => 'type',
            'done' => 'input',
            'value' => '150000',
            'narration' => 'Type the yearly cap in rand, if your agency uses one.',
            'why' => 'When an agent reaches it, the fees below change.',
        ],
        [
            'selector' => 'input[name="monthly_platform_fee"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type any monthly fee you charge an agent.',
            'why' => 'It is taken off when their pay is worked out.',
        ],
        [
            'selector' => 'div:has(> label > input[name="mentor_program_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on the mentor programme if new agents get a mentor.',
            'why' => 'The mentor earns a little extra from the new agent\'s deals.',
        ],
        [
            'selector' => 'div:has(> label > input[name="revenue_share_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on revenue share if agents earn from agents they bring in.',
            'why' => 'CoreX then works out that extra pay for you.',
        ],
        [
            'selector' => 'div:has(> label > input[name="portal_show_agency_commission_totals"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if agents may see branch and agency totals.',
            'why' => 'Some agencies prefer to keep those figures private.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'These numbers feed every agent\'s pay, so check them carefully.',
];
