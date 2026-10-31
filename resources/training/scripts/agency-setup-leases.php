<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-leases',
    'title' => 'Set up your rentals',
    'url' => '/corex/agency-setup/step/leases',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up how your agency handles rentals.',
    'steps' => [
        [
            'selector' => 'input[name="expiry_notice_window_days"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many days before a lease ends you want a warning.',
            'why' => 'You have time to renew it or find a new tenant.',
        ],
        [
            'selector' => 'input[name="tenant_notice_period_days"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how much notice a tenant gives before moving out.',
            'why' => 'Every new lease starts with this, and you can change it on each lease.',
        ],
        [
            'selector' => 'select[name="default_early_cancellation_allowed"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if tenants may cancel a fixed term early.',
            'why' => 'Each new lease starts with your choice.',
        ],
        [
            'selector' => 'label:has(input[type="checkbox"][name="tenant_portal_enabled"])',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Switch on the tenant portal so tenants can log in.',
            'why' => 'They can see their lease and report faults themselves.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'There are more rental settings below. You can come back to them later.',
];
