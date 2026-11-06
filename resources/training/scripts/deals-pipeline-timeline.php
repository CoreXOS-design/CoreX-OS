<?php

/**
 * Training script - Deals 4 of 5: the pipeline Timeline view - bars by date, today's line, zoom and re-dating. Starts at the register; the recording never drags a bar. Leaves the view on List again (last step), because the Pipeline link opens whichever view the user used last and the other scripts expect List.
 */
return [
    'key' => 'deals-pipeline-timeline',
    'title' => 'The deal timeline',
    'route' => 'deals-dr2.pipeline.timeline',
    'url' => '/deals-dr2?flow=pipeline-timeline',
    'specs' => ['.ai/specs/dr2-pipeline-spec.md', '.ai/specs/pipeline-dashboard.md'],
    'audience' => [],
    'related' => ['deals-pipeline-list', 'deals-work-orders'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is the same pipeline laid out on a timeline.',
    'steps' => [
        [
            'selector' => 'main a[href*="/pipeline/view"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the deal\'s pipeline from the register.',
            'why' => 'One page shows every step from offer to transfer.',
        ],
        [
            'selector' => '.toggle a[href$="/pipeline/timeline"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Choose Timeline.',
            'why' => 'You see the whole deal laid out by date.',
        ],
        [
            'selector' => '#dr2tl .ttile', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Each bar is a step. Its length is how many days it takes.',
            'why' => 'You see at a glance where the deal is slow.',
        ],
        [
            'selector' => '#dr2tl .today', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'The red line is today.',
            'why' => 'Steps to the left of it should already be done.',
        ],
        [
            'selector' => '#dr2tl .stagelegend', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Stage one is the conditions. Stage two is transfer and registration.',
            'why' => 'Transfer only starts once the conditions are met.',
        ],
        [
            'selector' => '#dr2tl .zoomctl', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Switch between Month, Week and Day.',
            'why' => 'Zoom out for the big picture, in for this week.',
        ],
        [
            'selector' => '#dr2tl .ttile[data-draggable="1"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Drag a bar left or right to change its dates.',
            'why' => 'You see what moves before you confirm.',
        ],
        [
            'selector' => '#dr2tl .addstep__toggle', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add a custom step for a special job, like a second inspection.',
            'why' => 'Extra jobs on this deal get tracked too.',
        ],
        [
            'selector' => '#dr2tl-feed', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Comments on the deal sit below the timeline.',
            'why' => 'The notes stay next to the dates.',
        ],
        [
            'selector' => '.toggle a[href$="/pipeline/list"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press List to go back to the step cards.',
            'why' => 'CoreX remembers your last choice for next time.',
        ],
    ],
    'outro' => 'Next, see how to send a work order.',
];
