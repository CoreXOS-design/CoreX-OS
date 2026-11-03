<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-matches',
    'title' => 'Set up buyer matches',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/matches',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up how new listings are matched to your buyers.',
    'steps' => [
        [
            'selector' => 'label:has(#f_matches_show_on_properties)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on matching buyers on the property page.',
            'why' => 'Your agent sees who to phone first.',
        ],
        [
            'selector' => '#f_matches_visibility_scope',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose who can see a buyer match.',
            'why' => 'It decides how far a buyer\'s details travel inside your agency.',
        ],
        [
            'selector' => '#f_matches_wa_message',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Write the WhatsApp message agents start with.',
            'why' => 'Nobody retypes the same opening line again and again.',
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
