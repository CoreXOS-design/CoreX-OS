<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-presentations',
    'title' => 'Set up your evaluations',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/presentations',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up how CoreX builds an evaluation for a seller.',
    'steps' => [
        [
            'selector' => '#f_presentations_default_period_months',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many months back to look for sales.',
            'why' => 'Only recent sales count when you price a house.',
        ],
        [
            'selector' => '#f_presentations_default_comp_scope',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose where to look for sales.',
            'why' => 'Staying inside the suburb is safer where suburbs differ a lot.',
        ],
        [
            'selector' => '#f_presentations_default_radius_m',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how far around the house to look, in metres.',
            'why' => 'One thousand metres is about a ten-minute walk.',
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
