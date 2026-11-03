<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-branches',
    'title' => 'Set up your offices and assistants',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/branches',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now add your offices, and say if your agents have assistants.',
    'steps' => [
        [
            'selector' => 'div:has(> label > input[name="assistants_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch this on if your agents have assistants.',
            'why' => 'Each assistant gets their own login, so nobody shares a password.',
        ],
        [
            'selector' => 'div:has(> label > input[name="assistant_fica_required_default"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if new assistants must send their ID documents.',
            'why' => 'It keeps your staff records in order for an inspection.',
        ],
        [
            'selector' => 'form[action$="/collection/branch"] input[name="name"]',
            'action' => 'type',
            'done' => 'input',
            'value' => 'Seaview Office',
            'narration' => 'Now add your offices. Type the name of your office.',
            'why' => 'Every agent, property and deal belongs to an office, so reports can compare them.',
        ],
        [
            'selector' => 'form[action$="/collection/branch"] input[name="code"]',
            'action' => 'type',
            'done' => 'input',
            'value' => 'SVO',
            'narration' => 'Type a short code for it.',
            'why' => 'The code shows on deal numbers and reports.',
        ],
        [
            'selector' => 'form[action$="/collection/branch"] button[type="submit"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Add branch. Do this for each office.',
            'why' => 'Access in Role Manager follows the office, so people see only their own.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: how your agents are paid.',
];
