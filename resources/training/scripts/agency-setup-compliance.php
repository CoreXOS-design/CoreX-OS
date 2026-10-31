<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-compliance',
    'title' => 'Set up your compliance',
    'url' => '/corex/agency-setup/step/compliance',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now tell CoreX who looks after compliance.',
    'steps' => [
        [
            'selector' => 'input[name="whistleblow_compliance_officer_email"]',
            'action' => 'type',
            'done' => 'input',
            'value' => 'compliance@youragency.example',
            'narration' => 'Type the email address of your compliance officer.',
            'why' => 'Compliance reports are sent to this address.',
        ],
        [
            'selector' => 'select[name="financial_year_start_month"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose the month your financial year starts.',
            'why' => 'The inspection pack uses it for your yearly lists.',
        ],
        [
            'selector' => 'input[name="fica_link_expiry_days"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many days a FICA link stays valid.',
            'why' => 'A client has time to finish, and old links stop working.',
        ],
        [
            'selector' => 'input[name="fica_current_months"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many months an approved FICA stays current.',
            'why' => 'CoreX warns you before it runs out.',
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
