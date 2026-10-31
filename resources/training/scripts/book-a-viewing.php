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
    'intro' => 'In this video you will book a viewing in your diary, and see why it matters for the buyer and for the seller.',
    'steps' => [
        [
            'selector' => '[data-tour="cal-cockpit"]', 'action' => 'highlight',
            'narration' => 'This is your diary. Every viewing you take buyers to is booked here.',
            'why' => 'The diary is the one place CoreX learns that a viewing happened, so everything that follows starts from here.',
        ],
        [
            'selector' => '[data-tour="cal-add"]', 'action' => 'click',
            'narration' => 'Click Add Event to start a new appointment.',
            'why' => 'A booked viewing is linked to the buyer and to the property, not just to a time slot.',
        ],
        [
            'selector' => '[data-tour="cal-event-title"]', 'action' => 'type', 'value' => 'Viewing with Bianca Buyer',
            'narration' => 'Give it a clear title, then choose the type Viewing.',
            'why' => 'The type decides what CoreX asks you for afterwards: a viewing asks for the buyer\'s feedback.',
        ],
        [
            'selector' => '[data-tour="cal-event-properties"]', 'action' => 'highlight',
            'narration' => 'Pick the property, or several if you are taking the buyer to more than one.',
            'why' => 'Each property gets its own feedback, so the seller of that property sees what buyers really said about it.',
        ],
        [
            'selector' => '[data-tour="cal-event-attendees"]', 'action' => 'highlight',
            'narration' => 'Add the buyer as the attendee.',
            'why' => 'That ties the viewing to the buyer\'s contact record, so their history shows every viewing they have been on.',
        ],
        [
            'selector' => '[data-tour="cal-event-reminder"]', 'action' => 'highlight',
            'narration' => 'Set a reminder, for example an hour before.',
            'why' => 'A reminder finds you anywhere in CoreX, so a booked viewing is never missed.',
        ],
        [
            'selector' => '[data-tour="cal-event-save"]', 'action' => 'highlight',
            'narration' => 'Click Create Event to save it.',
            'why' => 'After the viewing, you capture the feedback on this appointment, and it goes to the seller\'s live link, with the buyer\'s name left out.',
        ],
    ],
    'outro' => 'Next, watch how to capture the buyer\'s feedback after the viewing.',
];
