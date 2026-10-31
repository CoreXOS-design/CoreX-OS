<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-outgoing-mail',
    'title' => 'Send mail from your own address',
    'url' => '/corex/agency-setup/step/outgoing_mail',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now connect your own mailbox, so emails go out under your name.',
    'steps' => [
        [
            'selector' => 'label:has(#f_outgoing_enabled)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch this on to send mail from your own mailbox.',
            'why' => 'Signing invitations come from your own address, so fewer are rejected.',
        ],
        [
            'selector' => '#f_email_address',
            'action' => 'type',
            'done' => 'input',
            'value' => 'office@youragency.example',
            'narration' => 'Type the email address you send from.',
            'why' => 'This is the address your clients will see.',
        ],
        [
            'selector' => '#f_smtp_host',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type the outgoing mail server. Whoever hosts your email can tell you.',
            'why' => 'CoreX needs it to send mail for you.',
        ],
        [
            'selector' => '#f_password',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type the mailbox password.',
            'why' => 'It is stored scrambled and is never shown again.',
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
