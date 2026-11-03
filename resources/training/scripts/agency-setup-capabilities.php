<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-capabilities',
    'title' => 'Choose what your agency uses',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/capabilities',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now choose the parts of CoreX your agency will use.',
    'steps' => [
        [
            'selector' => 'label:has(input[name="feature_rollout_mode"][value="all"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose to switch everything on now, or bring features in gradually.',
            'why' => 'Gradual keeps your team on Properties and Contacts first, so nobody feels swamped.',
        ],
        [
            'selector' => 'div:has(> label > input[name="marketing_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Marketing if you promote listings with posts and brochures.',
            'why' => 'CoreX then keeps the posts and brochures with each property.',
        ],
        [
            'selector' => 'div:has(> label > input[name="matches_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Core Matches to match new listings to your buyers.',
            'why' => 'The right buyer shows up the moment a fitting property is added.',
        ],
        [
            'selector' => 'div:has(> label > input[name="split_branches_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Multi-branch offices if you work from more than one office.',
            'why' => 'Each branch then has its own agents, properties and deals.',
        ],
        [
            'selector' => 'div:has(> label > input[name="syndication_p24_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on Property24 to send your listings there by themselves.',
            'why' => 'You capture a listing once and it goes out to the portal.',
        ],
        [
            'selector' => 'div:has(> label > input[name="syndication_pp_enabled"])',
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
            'why' => 'Anything you leave off is simply hidden, and you can switch it on later.',
        ],
    ],
    'outro' => 'Next: your logo and colours.',
];
