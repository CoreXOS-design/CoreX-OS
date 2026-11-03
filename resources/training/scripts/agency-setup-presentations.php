<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-presentations',
    'title' => 'Set up your seller presentations',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/presentations',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up the price presentation you show a seller.',
    'steps' => [
        [
            'selector' => '#f_presentations_coverage_rich_threshold',
            'action' => 'type',
            'done' => 'input',
            'value' => '6',
            'narration' => 'Type how many similar sales make a strong case.',
            'why' => 'The seller sees how much proof stands behind your price.',
        ],
        [
            'selector' => '#f_presentations_default_period_months',
            'action' => 'type',
            'done' => 'input',
            'value' => '12',
            'narration' => 'Type how many months back to look for sales.',
            'why' => 'Only recent sales count when you price a house.',
        ],
        [
            'selector' => '#f_presentations_default_comp_scope',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose where to look for similar sales.',
            'why' => 'Staying inside the suburb is safer where suburbs differ a lot.',
        ],
        [
            'selector' => '#f_presentations_default_radius_m',
            'action' => 'type',
            'done' => 'input',
            'value' => '1000',
            'narration' => 'Type how far around the house to look, in metres.',
            'why' => 'One thousand metres is about a ten-minute walk.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: matching buyers to listings.',
];
