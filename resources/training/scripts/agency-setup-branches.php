<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-branches',
    'title' => 'Set up your offices and assistants',
    'url' => '/corex/agency-setup/step/branches',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up your offices and your agents\' assistants.',
    'steps' => [
        [
            'selector' => 'label:has(input[type="checkbox"][name="assistants_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Tick this if your agents have assistants.',
            'why' => 'Each assistant gets their own login, so nobody shares a password.',
        ],
        [
            'selector' => '#f_assistant_fica_required_default',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if new assistants must send their ID documents.',
            'why' => 'It keeps your staff records in order for an inspection.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'If you have more than one office, add each one on this screen.',
];
