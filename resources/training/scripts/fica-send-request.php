<?php

/**
 * Training script - FICA, agent side 1 of 2: start a FICA on a client and send the link. Selectors walked on QA1 as the training agent; wording follows the plain-words
 * rule (training-videos.md 9.13). Invented sample data only. Nothing is sent: the recording stops at the Send button and never presses it.
 */
return [
    'key' => 'fica-send-request',
    'title' => 'Ask a client for their FICA',
    'route' => 'compliance.fica.create',
    'url' => '/corex/compliance/fica/create',
    'specs' => ['.ai/specs/compliance.md'],
    'audience' => [],
    'related' => ['fica-agent-review', 'fica-client-form'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to ask a client for their FICA.',
    'steps' => [
        [
            'selector' => '[data-tour="comp-fica-create-intro"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'This is where a FICA request starts.',
            'why' => 'The law says you must check who your client is before a deal.',
        ],
        [
            'selector' => '[data-tour="comp-fica-create-contact"] input[type="text"]', 'action' => 'type', 'done' => 'input', 'value' => 'Priya',
            'narration' => 'Type the client\'s name, then pick them from the list.',
            'why' => 'The form then opens already filled with what you know about them.',
        ],
        [
            'selector' => '[data-tour="comp-fica-create-contact"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'The client needs an email address on their contact page.',
            'why' => 'That is where the private link is sent.',
        ],
        [
            'selector' => '[data-tour="comp-fica-create-send"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Send FICA Request.',
            'why' => 'The client gets an email with a link, so you do not chase paperwork.',
        ],
    ],
    'outro' => 'Next, see how to check a FICA when it comes back.',
];
