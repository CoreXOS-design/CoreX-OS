<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-settings-essentials',
    'title' => 'Find your main settings',
    'route' => 'corex.settings',
    'url' => '/corex/settings',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here are the settings you will use most.',
    'steps' => [
        [
            'selector' => '[data-tour="settings-rail-company"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Open Company Settings first. Add your name, contact details and logo.',
            'why' => 'They print on your documents and emails.',
        ],
        [
            'selector' => '[data-tour="settings-rail-commission"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open Commission and revenue share.',
            'why' => 'This is where agent pay is set.',
        ],
        [
            'selector' => 'input[name="commission_split_agent"]',
            'action' => 'type',
            'done' => 'input',
            'value' => '70',
            'wait_for' => 'input[name="commission_split_agent"]',
            'narration' => 'Type the share of each commission that goes to the agent.',
            'why' => 'Every deal then works out agent pay from this.',
        ],
        [
            'selector' => '[data-tour="settings-rail-compliance-switches"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open Compliance Switches.',
            'why' => 'You choose which checks CoreX makes before a property is marketed.',
        ],
        [
            'selector' => '[data-tour="settings-rail-notifications"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open Notifications.',
            'why' => 'You choose what CoreX reminds your team about.',
        ],
        [
            'selector' => '[data-tour="settings-rail-agency-setup"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Come back to the Setup Guide any time.',
            'why' => 'It walks you through every setting again, one screen at a time.',
        ],
    ],
    'outro' => 'Every setting from the Setup Guide lives here too.',
];
