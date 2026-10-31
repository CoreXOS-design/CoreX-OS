<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-welcome',
    'title' => 'Start your agency setup',
    'url' => '/corex/agency-setup/step/welcome',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Welcome. This guide takes you through setting up your agency.',
    'steps' => [
        [
            'selector' => 'h1',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'This is the start of your agency setup.',
            'why' => 'It is a few short screens, and your answers are saved as you go.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'Press the button when you are ready to begin.',
];
