<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-properties',
    'title' => 'Set up your listings',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/properties',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up how your property list behaves.',
    'steps' => [
        [
            'selector' => '#f_properties_per_page',
            'action' => 'type',
            'done' => 'input',
            'value' => '20',
            'narration' => 'Type how many properties load on a page.',
            'why' => 'A smaller number loads faster on a phone.',
        ],
        [
            'selector' => '#f_mandate_expiry_warn_days',
            'action' => 'type',
            'done' => 'input',
            'value' => '30',
            'narration' => 'Type how many days before a mandate ends your agents get a warning.',
            'why' => 'They can renew it before you lose the listing.',
        ],
        [
            'selector' => 'div:has(> label > input[name="mandate_expiry_lock_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on the expiry lock.',
            'why' => 'Nobody can quietly change an expiry date. An extension needs the signed form.',
        ],
        [
            'selector' => 'div:has(> label > input[name="flag_property_under_offer_on_deal"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if a new deal marks the property Under Offer.',
            'why' => 'Your team and the portals see it straight away.',
        ],
        [
            'selector' => 'div:has(> label > input[name="revert_property_on_deal_declined"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if the property goes back on the market when a deal falls through.',
            'why' => 'A lapsed deal does not leave a house stuck as Under Offer.',
        ],
        [
            'selector' => 'input[name="name"]',
            'action' => 'type',
            'done' => 'input',
            'value' => 'Strong room',
            'narration' => 'Type a place where title deeds are kept, then press Add.',
            'why' => 'Anyone can find where the paper deed is, so nobody hunts for it.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: seller presentations.',
];
