<?php

/**
 * AT-525 - how-to: Market Intelligence, where to start today (spec .ai/specs/training-videos.md section 9). The WHY comes from .ai/specs/mic-work-tab.md (tiles, the property
 * record, claiming, P24 alerts, the neighbours-of-a-sale rule, the weekly refresh). Recorded only in the CoreX Training Academy, whose Market Intelligence rows are invented
 * (TrainingMicSeeder) - no real portal advert, owner or deeds name can appear. Wording is agency-neutral on purpose.
 */
return [
    'key' => 'mic-work',
    'title' => 'Market Intelligence: where to start today',
    'route' => 'market-intelligence.work',
    'url' => '/corex/market-intelligence/work',
    'tour' => null,
    'specs' => ['.ai/specs/mic-work-tab.md'],
    'audience' => [],
    'related' => ['book-a-viewing'],
    'login_as' => 'agent',
    'approved' => true,
    'intro' => 'Here is where to start your day in Market Intelligence.',
    'steps' => [
        [
            'selector' => '[data-tour="mic-tiles"]', 'action' => 'highlight',
            'narration' => 'Start with these boxes. Each one counts a group of houses that need you.',
            'why' => 'Press one to see only those houses, so you phone the likeliest sellers first.',
        ],
        [
            'selector' => '[data-qa="card-toggle"]', 'action' => 'click',
            'narration' => 'Press Card on a house to see everything we know about it.',
            'why' => 'The adverts, price changes and owner are all in one place, so you do not search five screens.',
        ],
        [
            'selector' => '.mi-suggested-chip', 'action' => 'highlight',
            'narration' => 'Press Pitch now to write to the owner.',
            'why' => 'It holds the house for you, and shows the buyers waiting in that area.',
        ],
        [
            'selector' => '[data-tour="mic-claim"] button', 'action' => 'highlight',
            'narration' => 'To hold a house without pitching yet, press Claim.',
            'why' => 'Then it is yours, and no colleague phones the same owner.',
        ],
        [
            'selector' => '[data-qa="envelope"]', 'action' => 'highlight',
            'narration' => 'This envelope means a Property24 alert email matched this house.',
            'why' => 'Those emails arrive first, so you hear about price drops early.',
        ],
        [
            'selector' => '[data-qa="tile-10"]', 'action' => 'click',
            'narration' => 'Press Neighbours to see homes that sold or went under offer this month.',
            'why' => 'Owners next door to a sale are your best leads, so phone them.',
        ],
        [
            'selector' => '[data-qa="trigger-row"] > a', 'action' => 'click',
            'narration' => 'Open one to see the homes around it, who owns them, and when you last called.',
            'why' => 'So you know who to phone, and nobody gets phoned twice.',
        ],
        [
            'selector' => '[data-qa="tile-11"]', 'action' => 'click',
            'narration' => 'Press Data refreshed to see when each area was last checked.',
            'why' => 'Check every area each week. If a house disappears, it has probably sold.',
        ],
        [
            'selector' => '[data-qa="refresh-row"]', 'action' => 'highlight',
            'narration' => 'The oldest area is first. Red means it is overdue.',
            'why' => 'Old information makes the sold list wrong, so check it again.',
        ],
    ],
    'outro' => 'Next, see how to book a viewing in your diary.',
];
