<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-capabilities',
    'title' => 'Choose what your agency uses',
    'url' => '/corex/agency-setup/step/capabilities',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now choose the parts of CoreX your agency will use.',
    'steps' => [
        [
            'selector' => 'label:has(input[type="checkbox"][name="marketing_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Marketing if you promote your listings with posts and brochures.',
            'why' => 'CoreX then keeps the posts and brochures with each property.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="matches_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Core Matches to match new listings to your buyers.',
            'why' => 'The right buyer shows up the moment a fitting property is added.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="split_branches_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Multi-branch offices if you work from more than one office.',
            'why' => 'Each branch then has its own agents, properties and deals.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="syndication_p24_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Property24 to send your listings there by themselves.',
            'why' => 'You capture a listing once and it goes out to the portal.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="syndication_pp_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Do the same for Private Property if you advertise there.',
            'why' => 'It saves your agents typing every listing twice.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'Anything you leave off is simply hidden. You can switch it on later.',
];
