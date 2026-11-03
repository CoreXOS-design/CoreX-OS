<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
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
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up how your contacts list behaves.',
    'steps' => [
        [
            'selector' => 'div.space-y-1:has(input[name="lead_response_hours[mon][start]"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Set the hours your office answers enquiries.',
            'why' => 'Only these hours count when answer times are measured.',
        ],
        [
            'selector' => '#f_contacts_per_page',
            'action' => 'type',
            'done' => 'input',
            'value' => '24',
            'narration' => 'Type how many contacts load on a page.',
            'why' => 'A smaller number loads faster on a phone.',
        ],
        [
            'selector' => '#f_lead_response_target_minutes',
            'action' => 'type',
            'done' => 'input',
            'value' => '60',
            'narration' => 'Type how fast you aim to answer a new enquiry, in minutes.',
            'why' => 'Enquiries that wait a long time are easily lost.',
        ],
        [
            'selector' => 'input[name="name"]',
            'action' => 'type',
            'done' => 'input',
            'value' => 'Walk-in',
            'narration' => 'Type where your enquiries come from, then press Add.',
            'why' => 'Reports then show which source brings the most business.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: compliance.',
];
