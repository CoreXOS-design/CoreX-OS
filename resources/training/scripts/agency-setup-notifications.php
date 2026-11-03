<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-notifications',
    'title' => 'Choose your reminders',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/notifications',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now choose what CoreX reminds your team about.',
    'steps' => [
        [
            'selector' => 'select[name="dashboard_settings_mode"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose who controls reminders.',
            'why' => 'Either each agent sets their own, or your agency sets them for everyone.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="fica_reminders"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Keep the FICA reminders on.',
            'why' => 'You hear before a client\'s FICA runs out.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="ffc_reminders"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Keep the Fidelity Fund Certificate reminders on.',
            'why' => 'An agent cannot trade with an expired certificate.',
        ],
        [
            'selector' => 'input[name="idle_threshold_days"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many days before an untouched listing is flagged.',
            'why' => 'The listing agent then gets a nudge.',
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
