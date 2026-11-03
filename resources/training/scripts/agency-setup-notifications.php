<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
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
    'login_as' => 'admin',
    'approved' => true,
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
            'selector' => 'label:has(input[name="fica_reminders"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Keep the FICA reminders on.',
            'why' => 'You hear before a client\'s FICA runs out.',
        ],
        [
            'selector' => 'label:has(input[name="ffc_reminders"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Keep the Fidelity Fund Certificate reminders on.',
            'why' => 'An agent cannot trade with an expired certificate.',
        ],
        [
            'selector' => 'div:has(> label input[name="notify_email"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose how people are told: in CoreX, by email, or on their phone.',
            'why' => 'Reminders reach people where they will actually see them.',
        ],
        [
            'selector' => 'input[name="idle_threshold_days"]',
            'action' => 'type',
            'done' => 'input',
            'value' => '21',
            'narration' => 'Type how many days before an untouched listing is flagged.',
            'why' => 'The listing agent then gets a nudge.',
        ],
        [
            'selector' => 'label:has(input[name="open_hours_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on quiet hours if nobody should be pinged at night.',
            'why' => 'Reminders wait until the working day starts.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: who can do what.',
];
