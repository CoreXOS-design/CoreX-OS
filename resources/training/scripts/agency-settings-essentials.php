<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-settings-essentials',
    'title' => 'The settings every new agency must set',
    'route' => 'corex.settings',
    'url' => '/corex/settings',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'These are the few settings every new agency should set first.',
    'steps' => [
        [
            'selector' => '[data-tour="settings-rail-company"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Open Company Settings first. Add your name, contact details and logo.',
            'why' => 'They print on every document and email you send.',
        ],
        [
            'selector' => '[data-tour="settings-rail-commission"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open Commission and revenue share.',
            'why' => 'This decides what each agent is paid.',
        ],
        [
            'selector' => 'input[name="commission_split_agent"]',
            'action' => 'highlight',
            'done' => 'none',
            'wait_for' => 'input[name="commission_split_agent"]',
            'narration' => 'Type the share of each commission that goes to the agent.',
            'why' => 'It is the starting point for every agent\'s pay.',
        ],
        [
            'selector' => '[data-tour="settings-rail-compliance-switches"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open Compliance Switches.',
            'why' => 'They stop a listing going out before its papers are in order.',
        ],
        [
            'selector' => '[data-tour="settings-rail-notifications"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open Notifications.',
            'why' => 'You decide what CoreX reminds your team about.',
        ],
        [
            'selector' => '[data-tour="settings-rail-agency-setup"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Come back to the Setup Guide any time.',
            'why' => 'It walks you through every setting, one step at a time.',
        ],
    ],
    'outro' => 'Do these five and your agency is ready to work.',
];
