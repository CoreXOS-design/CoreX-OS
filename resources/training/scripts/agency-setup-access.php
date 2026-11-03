<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-access',
    'title' => 'Finish your setup',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/access',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'One last choice, and your agency is set up.',
    'steps' => [
        [
            'selector' => 'div:has(> label > input[name="desk_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if agents may share their screen with CoreX support.',
            'why' => 'Support can then see the problem and fix it with them.',
        ],
        [
            'selector' => '#f_desk_control_idle_minutes',
            'action' => 'type',
            'done' => 'input',
            'value' => '5',
            'narration' => 'Type how many idle minutes before a support take-over ends.',
            'why' => 'Support never keeps control of a screen by mistake.',
        ],
        [
            'selector' => 'div:has(> label > input[name="require_external_access_authorization"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if a developer must ask your permission first.',
            'why' => 'Nobody enters your agency without you knowing.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Finish setup.',
            'why' => 'Your agency is ready and you land on your dashboard.',
        ],
    ],
    'outro' => 'You are all set.',
];
