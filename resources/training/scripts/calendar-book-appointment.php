<?php

/**
 * Training script - Calendar 2 of 3: book an appointment (a property evaluation visit; the approved "Book a viewing in the diary" video, owned by cc1, covers a viewing).
 * Selectors walked on QA1 as the training agent. Route: none on purpose (the diary's route belongs to that video; ?flow=book keeps this one apart). Nothing is saved: the
 * recording fills in the box, points at Create Event and closes it without saving.
 */
return [
    'key' => 'calendar-book-appointment',
    'title' => 'Book an appointment in the diary',
    'url' => '/corex/command-center/calendar?flow=book',
    'specs' => ['.ai/specs/calendar-viewing-feedback.md'],
    'audience' => [],
    'related' => ['book-a-viewing', 'calendar-view-diary', 'calendar-viewing-feedback'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to book an appointment in your diary.',
    'steps' => [
        [
            'selector' => '[data-tour="cal-add"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press Add Event.',
            'why' => 'An appointment in the diary is one you will not forget.',
        ],
        [
            'selector' => '[data-tour="cal-event-title"]', 'action' => 'type', 'done' => 'input', 'value' => 'Evaluation visit with Sipho Seller', 'wait_for' => '[data-tour="cal-event-title"]',
            'narration' => 'Type a name for the appointment.',
            'why' => 'You and your team see this name in the diary.',
        ],
        [
            'selector' => '[data-tour="cal-event-type"]', 'action' => 'select', 'done' => 'change', 'value' => 'property_evaluation',
            'narration' => 'Choose the type, like Property evaluation.',
            'why' => 'CoreX then knows what to prepare and what to ask you afterwards.',
        ],
        [
            'selector' => '[data-tour="cal-event-start"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Choose the day and the time.',
            'why' => 'CoreX warns you if it clashes with something else.',
        ],
        [
            'selector' => '[data-tour="cal-event-properties"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Link the property.',
            'why' => 'The appointment then shows on the property\'s page too.',
        ],
        [
            'selector' => '[data-tour="cal-event-attendees"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add who is coming.',
            'why' => 'It shows on their contact page, and colleagues get an invitation.',
        ],
        [
            'selector' => '[data-tour="cal-event-reminder"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Set a reminder, like one hour before.',
            'why' => 'You get a nudge wherever you are in CoreX.',
        ],
        [
            'selector' => '[data-tour="cal-event-save"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Create Event to save it.',
            'why' => 'It appears in your diary straight away.',
        ],
    ],
    'outro' => 'Next, see how to add feedback after a viewing.',
];
