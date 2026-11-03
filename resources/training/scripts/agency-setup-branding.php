<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-branding',
    'title' => 'Add your logo and colours',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/branding',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now make CoreX look like your agency.',
    'steps' => [
        [
            'selector' => 'input[name="logo"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Add your logo here.',
            'why' => 'It prints on your documents and emails, and CoreX picks your colours from it.',
        ],
        [
            'selector' => 'input[name="default_color"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Check the header colour. Change it if it is not your brand colour.',
            'why' => 'Your page headers and letterheads use this colour.',
        ],
        [
            'selector' => 'input[name="button_color"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose the button colour.',
            'why' => 'Every main button in CoreX uses it.',
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
