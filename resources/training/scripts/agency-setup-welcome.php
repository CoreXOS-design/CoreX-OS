<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-welcome',
    'title' => 'Start your agency setup',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/welcome',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Welcome. This is how you set up your agency in CoreX.',
    'steps' => [
        [
            'selector' => 'main .px-6.pt-6.pb-4',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'This is the start of your setup. It is a few short screens.',
            'why' => 'You tell CoreX about your agency once, and it then works the way you work.',
        ],
        [
            'selector' => 'h1',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Every screen explains what it asks for before it asks.',
            'why' => 'You always know why a question is there, so you can answer with confidence.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue to begin.',
            'why' => 'Each screen saves when you press this, so you can stop and come back later.',
        ],
    ],
    'outro' => 'Next: your agency details.',
];
