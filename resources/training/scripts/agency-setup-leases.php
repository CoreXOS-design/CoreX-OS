<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-leases',
    'title' => 'Set up your rentals',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/leases',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up how your agency handles rentals.',
    'steps' => [
        [
            'selector' => 'div.overflow-x-auto:has(input[name="shown_field_keys[]"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Pick which questions a tenant answers on the rental application.',
            'why' => 'Tenants are asked only what you need, so applications come back complete.',
        ],
        [
            'selector' => 'div.overflow-x-auto:has(input[name="required_field_keys[]"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Tick Compulsory beside the ones a tenant must answer.',
            'why' => 'An application cannot be sent in until those are filled.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Every new application then uses your questions.',
        ],
    ],
    'outro' => 'There are more rental settings in Settings. You can come back to them any time.',
];
