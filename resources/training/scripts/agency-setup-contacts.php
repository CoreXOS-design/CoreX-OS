<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-contacts',
    'title' => 'Set up your contacts',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/contacts',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up how your contacts list behaves.',
    'steps' => [
        [
            'selector' => 'input[name="contacts_per_page"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many contacts load at a time.',
            'why' => 'A smaller number loads faster on a phone.',
        ],
        [
            'selector' => 'input[name="lead_response_target_minutes"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how fast you aim to answer a new enquiry, in minutes.',
            'why' => 'Enquiries that wait a long time are easily lost.',
        ],
        [
            'selector' => 'input[name="lead_response_hours[mon][start]"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Set the hours your office answers enquiries.',
            'why' => 'Only these hours count when answer times are measured.',
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
