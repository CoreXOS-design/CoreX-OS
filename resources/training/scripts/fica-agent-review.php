<?php

/**
 * Training script - FICA, agent side 2 of 2: find the FICA that came back, check it and approve it. Selectors walked on QA1 as the training agent (the training agency
 * has one invented FICA waiting for review - TrainingAgencySeeder). Nothing is approved: the recording only points at the buttons and never presses them.
 */
return [
    'key' => 'fica-agent-review',
    'title' => 'Check a FICA and approve it',
    'route' => 'compliance.fica.index',
    'url' => '/corex/compliance/fica',
    'specs' => ['.ai/specs/compliance.md'],
    'audience' => [],
    'related' => ['fica-send-request', 'fica-client-form'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to check a FICA when the client has filled it in.',
    'steps' => [
        [
            'selector' => '[data-tour="fica-tabs"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'These tabs show where each FICA stands.',
            'why' => 'Awaiting Agent Review is the list that needs you today.',
        ],
        [
            'selector' => '[data-tour="fica-search"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'To find one client, search for their name.',
            'why' => 'It is quicker than scrolling when you have many.',
        ],
        [
            'selector' => '[data-tour="fica-tabs"] a[href*="tab=submitted"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press Awaiting Agent Review.',
            'why' => 'These clients have filled in the form and are waiting for you.',
        ],
        [
            'selector' => '[data-tour="fica-table"] tbody a.text-xs', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the one you want to check.',
            'why' => 'You see exactly what the client filled in.',
        ],
        [
            'selector' => 'div.rounded-md.p-5:has(input[name="checklist.identity_docs"])', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Go through the checklist. Is the ID there? Is the address proved?',
            'why' => 'This is your proof that you checked, if the regulator ever asks.',
        ],
        [
            'selector' => 'form[action$="/agent-approve"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Choose a risk rating and how you met them. Then press Approve.',
            'why' => 'Your approval goes to the compliance officer for the final sign-off.',
        ],
        [
            'selector' => 'form[action$="/request-corrections"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'If something is wrong, ask the client to fix it here.',
            'why' => 'They get a link to correct it, and you keep the rest.',
        ],
    ],
    'outro' => 'Next, see what the client sees when they fill it in.',
];
