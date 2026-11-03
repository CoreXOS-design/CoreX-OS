<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-roles',
    'title' => 'See who can do what',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/roles',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'This screen explains roles. There is nothing to fill in.',
    'steps' => [
        [
            'selector' => '#wizard-step-form .space-y-6 > div:nth-child(1)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'These are the roles your agency starts with.',
            'why' => 'Each role decides what a person can see and do.',
        ],
        [
            'selector' => '#wizard-step-form .space-y-6 > div:nth-child(2)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Here is an example. Add a new agent, and give them the Agent role.',
            'why' => 'You set nothing else up, because the role already has the right access.',
        ],
        [
            'selector' => '#wizard-step-form .space-y-6 > div:nth-child(3)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Roles say what a person may do. Offices say whose records they may see.',
            'why' => 'The two together keep each agent to their own work.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'There is nothing to save here, so you simply move on.',
        ],
    ],
    'outro' => 'Next: learn how to create a role in Role Manager.',
];
