<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-compliance',
    'title' => 'Set up your compliance',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/compliance',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now tell CoreX who looks after compliance.',
    'steps' => [
        [
            'selector' => 'input[name="whistleblow_compliance_officer_email"]',
            'action' => 'type',
            'done' => 'input',
            'value' => 'compliance@bluegumrealty.example',
            'narration' => 'Type the email address of your compliance officer.',
            'why' => 'Compliance reports are sent to this address.',
        ],
        [
            'selector' => 'div:has(> div > label > input[name="whistleblow_approver_user_ids[]"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Tick the people who may approve compliance reports.',
            'why' => 'Only the people you choose can decide on a report.',
        ],
        [
            'selector' => '#f_financial_year_start_month',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose the month your financial year starts.',
            'why' => 'The inspection pack uses it for your yearly lists.',
        ],
        [
            'selector' => '#f_fica_link_expiry_days',
            'action' => 'type',
            'done' => 'input',
            'value' => '14',
            'narration' => 'Type how many days a FICA link stays valid.',
            'why' => 'A client has time to finish, and old links stop working.',
        ],
        [
            'selector' => '#f_fica_current_months',
            'action' => 'type',
            'done' => 'input',
            'value' => '11',
            'narration' => 'Type how many months an approved FICA stays current.',
            'why' => 'CoreX warns you before it runs out.',
        ],
        [
            'selector' => 'div:has(> div > label > input[name="document_authoriser_roles[]"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Tick who may approve the documents CoreX prepares.',
            'why' => 'A lease or mandate goes out only after one of these people agrees.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: your outgoing mail.',
];
