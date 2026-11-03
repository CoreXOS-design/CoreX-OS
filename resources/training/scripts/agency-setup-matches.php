<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
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
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up how new listings are matched to your buyers.',
    'steps' => [
        [
            'selector' => 'div:has(> label > input[name="matches_show_on_properties"])',
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
            'action' => 'type',
            'done' => 'input',
            'value' => 'Hi, a new home just came on that suits what you are looking for.',
            'narration' => 'Write the WhatsApp message agents start with.',
            'why' => 'Nobody retypes the same opening line again and again.',
        ],
        [
            'selector' => '#f_matches_email_subject',
            'action' => 'type',
            'done' => 'input',
            'value' => 'A new home for you',
            'narration' => 'Type the subject line for the match email.',
            'why' => 'A clear subject gets the email opened.',
        ],
        [
            'selector' => 'div:has(> div > label > input[name="core_matches_excluded_buyer_states[]"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Tick the kinds of buyer who should not get matches.',
            'why' => 'Buyers who already bought or went quiet are left alone.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: Market Intelligence.',
];
