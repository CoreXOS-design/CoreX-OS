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
    'intro' => 'In this video you will see where to start your day in Market Intelligence, and what each part of the Work screen does.',
    'steps' => [
        [
            'selector' => '[data-tour="mic-tiles"]', 'action' => 'highlight',
            'narration' => 'These tiles are where to start today. Each counts a pocket of the market that needs you.',
            'why' => 'One click shows only that pocket, so you work the likeliest mandates first.',
        ],
        [
            'selector' => '[data-qa="work-row"] > div', 'action' => 'click',
            'narration' => 'Click a property to open it in place.',
            'why' => 'Both portals, alerts, price history and the deeds owner are matched into one card, so everything we know is in one place.',
        ],
        [
            'selector' => '[data-qa="work-row"] a.corex-btn-outline', 'action' => 'highlight',
            'narration' => 'To go after it, press Claim.',
            'why' => 'Claiming makes it yours, feeds your pipeline and Road to Sold, and stops a colleague chasing the same owner.',
        ],
        [
            'selector' => '[data-qa="row-sources"] span[title="Seen in a P24 e-mail alert"]', 'action' => 'highlight',
            'narration' => 'The envelope means a Property24 alert email matched this property.',
            'why' => 'Alert emails arrive before the next portal read, so it is an early sign of a new listing or price drop.',
        ],
        [
            'selector' => '[data-qa="tile-10"]', 'action' => 'click',
            'narration' => 'Neighbours of a sale lists homes that sold, went under offer or registered this month.',
            'why' => 'Owners next door to a sale are your warmest prospects, so canvass the neighbours after every sale.',
        ],
        [
            'selector' => '[data-qa="trigger-row"] > a', 'action' => 'click',
            'narration' => 'Open one to see the homes around it, their owner and your last contact.',
            'why' => 'You know who to call, and nobody is missed or phoned twice.',
        ],
        [
            'selector' => '[data-qa="tile-11"]', 'action' => 'click',
            'narration' => 'Data refreshed shows when each suburb was last read.',
            'why' => 'Refresh weekly, because a listing gone from a fresh read has probably sold.',
        ],
        [
            'selector' => '[data-qa="refresh-row"]', 'action' => 'highlight',
            'narration' => 'Oldest first, red when overdue. Open it on the portal and read it again.',
            'why' => 'A stale suburb makes the Gone and Sold tiles unreliable.',
        ],
    ],
    'outro' => 'Next, watch how to book a viewing in your diary.',
];
