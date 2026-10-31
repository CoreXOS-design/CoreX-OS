<?php

/**
 * AT-525 - the first generated how-to: the diary and booking a viewing (spec .ai/specs/training-videos.md section 9). The WHY comes from
 * .ai/specs/calendar-viewing-feedback.md (what a viewing's feedback feeds) and the calendar tour (selectors, order).
 */
return [
    'key' => 'book-a-viewing',
    'title' => 'Book a viewing in the diary',
    'route' => 'command-center.calendar',
    'url' => '/corex/command-center/calendar',
    'tour' => 'calendar',
    'specs' => ['.ai/specs/calendar-viewing-feedback.md'],
    'audience' => [],
    'related' => ['capture-viewing-feedback', 'seller-live-link'],
    'login_as' => 'agent',
    'approved' => true,
    'intro' => 'Here is how to put a viewing in your diary.',
    'steps' => [
        [
            'selector' => '[data-tour="cal-cockpit"]', 'action' => 'highlight',
            'narration' => 'This is your diary. Every viewing goes in here.',
            'why' => 'That is how CoreX knows the viewing happened, and asks you for feedback afterwards.',
        ],
        [
            'selector' => '[data-tour="cal-add"]', 'action' => 'click',
            'narration' => 'Press Add Event.',
            'why' => 'The viewing is then tied to the buyer and the house, not just a time.',
        ],
        [
            'selector' => '[data-tour="cal-event-title"]', 'action' => 'type', 'value' => 'Viewing with Bianca Buyer',
            'narration' => 'Type a name for the viewing.',
            'why' => 'You and your team will see this name in the diary.',
        ],
        [
            'selector' => '[data-tour="cal-event-type"]', 'action' => 'select', 'value' => 'viewing',
            'narration' => 'Choose Viewing as the type.',
            'why' => 'Then CoreX asks for the buyer\'s feedback after the viewing.',
        ],
        [
            'selector' => '[data-tour="cal-event-properties"]', 'action' => 'highlight',
            'narration' => 'Pick the house. Pick more than one if you are showing several.',
            'why' => 'Each house gets its own feedback, so each seller sees what buyers said.',
        ],
        [
            'selector' => '[data-tour="cal-event-attendees"]', 'action' => 'highlight',
            'narration' => 'Add the buyer.',
            'why' => 'Then every viewing that buyer goes on shows on their page.',
        ],
        [
            'selector' => '[data-tour="cal-event-reminder"]', 'action' => 'highlight',
            'narration' => 'Set a reminder, like one hour before.',
            'why' => 'So you never miss a viewing, wherever you are in CoreX.',
        ],
        [
            'selector' => '[data-tour="cal-event-save"]', 'action' => 'highlight',
            'narration' => 'Press Create Event to save it.',
            'why' => 'After the viewing, add the feedback here. The seller sees it on their live link, without the buyer\'s name.',
        ],
    ],
    'outro' => 'Next, see how to add feedback after a viewing.',
];
