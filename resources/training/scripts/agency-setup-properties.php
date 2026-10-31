<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-properties',
    'title' => 'Set up your listings',
    'url' => '/corex/agency-setup/step/properties',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up how your property list behaves.',
    'steps' => [
        [
            'selector' => '#f_mandate_expiry_warn_days',
            'action' => 'type',
            'done' => 'input',
            'value' => '30',
            'narration' => 'Type how many days before a mandate ends your agents get a warning.',
            'why' => 'They can renew it before you lose the listing.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="mandate_expiry_lock_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on the expiry lock.',
            'why' => 'Nobody can quietly change an expiry date. An extension needs the signed form.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="flag_property_under_offer_on_deal"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if a new deal marks the property Under Offer.',
            'why' => 'Your team and the portals see it straight away.',
        ],
        [
            'selector' => '#f_properties_per_page',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many properties load at a time.',
            'why' => 'A smaller number loads faster on a phone.',
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
