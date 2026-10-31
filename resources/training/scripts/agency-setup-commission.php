<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-commission',
    'title' => 'Set up agent pay',
    'url' => '/corex/agency-setup/step/commission',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
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
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type the yearly cap in rand, if your agency uses one.',
            'why' => 'Once an agent reaches it, the fees below change.',
        ],
        [
            'selector' => 'input[name="monthly_platform_fee"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type any monthly fee you charge an agent, in rand.',
            'why' => 'It is taken off when their pay is worked out.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="mentor_program_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on the mentor programme if new agents get a mentor.',
            'why' => 'The mentor earns a little extra from the new agent\'s deals.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="portal_show_agency_commission_totals"])',
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
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'These numbers feed every agent\'s pay, so check them carefully.',
];
