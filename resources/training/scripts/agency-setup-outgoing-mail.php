<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-outgoing-mail',
    'title' => 'Send mail from your own address',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/outgoing_mail',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now connect your own mailbox, so emails go out under your name.',
    'steps' => [
        [
            'selector' => 'div:has(> label > input[name="outgoing_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch this on to send mail from your own mailbox.',
            'why' => 'Signing invitations come from your own address, so fewer are lost to spam.',
        ],
        [
            'selector' => '#f_email_address',
            'action' => 'type',
            'done' => 'input',
            'value' => 'office@bluegumrealty.example',
            'narration' => 'Type the email address you send from.',
            'why' => 'This is the address your clients will see.',
        ],
        [
            'selector' => '#f_smtp_host',
            'action' => 'type',
            'done' => 'input',
            'value' => 'mail.bluegumrealty.example',
            'narration' => 'Type the outgoing mail server. Whoever hosts your email can tell you.',
            'why' => 'CoreX needs it to send mail for you.',
        ],
        [
            'selector' => '#f_username',
            'action' => 'type',
            'done' => 'input',
            'value' => 'office@bluegumrealty.example',
            'narration' => 'Type the mailbox username.',
            'why' => 'It proves to the mail server that the mailbox is yours.',
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
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: reminders.',
];
