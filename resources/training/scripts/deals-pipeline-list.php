<?php

/**
 * Training script - Deals 3 of 5: the pipeline List view - the step cards, completing a step, and the tabs on the right. Starts at the register and opens the deal's pipeline; the recording never completes a step. The training agent's saved view is List (seeded), so the Pipeline link lands on it.
 */
return [
    'key' => 'deals-pipeline-list',
    'title' => 'The deal pipeline',
    'route' => 'deals-dr2.pipeline.list',
    'url' => '/deals-dr2?flow=pipeline-list',
    'specs' => ['.ai/specs/dr2-pipeline-spec.md', '.ai/specs/pipeline-dashboard.md'],
    'audience' => [],
    'related' => ['deals-pipeline-timeline', 'deals-work-orders'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is a deal\'s pipeline, step by step.',
    'steps' => [
        [
            'selector' => 'main a[href*="/pipeline/view"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the deal\'s pipeline from the register.',
            'why' => 'One page shows every step from offer to transfer.',
        ],
        [
            'selector' => '#dr2ls-list', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Each card is one step, with its due date and what it waits on.',
            'why' => 'You know whose turn it is before you phone anyone.',
        ],
        [
            'selector' => '#dr2ls-sortbar', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Sort by step order or by due date.',
            'why' => 'Due date puts the most urgent step first.',
        ],
        [
            'selector' => '#dr2ls .dr2-bt--go', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Mark complete when a step is done.',
            'why' => 'The next steps open by themselves, so the deal keeps moving.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-structure"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'On the right, Deal Structure shows what this deal was built from.',
            'why' => 'The conditions decide which steps you see.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-wo"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Supplier Work Orders lists the certificates the deal needs, like electrical.',
            'why' => 'The supplier is emailed when its step is reached.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-docs"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Documents keeps every file filed on this deal.',
            'why' => 'Nothing is lost in somebody\'s inbox.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-email"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Email Parties sends documents to the seller, buyer or attorney.',
            'why' => 'Everyone gets the right documents from one place.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-comms"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Linked Emails shows the emails you have linked to this deal.',
            'why' => 'The whole conversation sits with the sale.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-pi"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Proforma Invoice is ready once the deal is Granted.',
            'why' => 'Your invoice comes from the deal, with no retyping.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-comments"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Comments is where you and your team write notes on the deal.',
            'why' => 'Everyone sees the same story, so nobody asks twice.',
        ],
    ],
    'outro' => 'Next, see the same pipeline on a timeline.',
];
