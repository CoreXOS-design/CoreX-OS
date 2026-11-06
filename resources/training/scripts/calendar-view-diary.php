<?php

/**
 * Training script - Calendar 1 of 3: read your diary. Selectors walked on QA1 as the training agent. Route: none on purpose (the diary's own route belongs to the approved
 * "Book a viewing in the diary" video, which cc1 owns; ?flow=view keeps this one apart). Read-only: nothing is created or saved.
 */
return [
    'key' => 'calendar-view-diary',
    'title' => 'Read your diary',
    'url' => '/corex/command-center/calendar?flow=view',
    'specs' => ['.ai/specs/spec-calendar-module.md'],
    'audience' => [],
    'related' => ['book-a-viewing', 'calendar-book-appointment', 'calendar-viewing-feedback'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to read your diary.',
    'steps' => [
        [
            'selector' => '[data-tour="cal-cockpit"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Everything you have to do is in this diary.',
            'why' => 'Viewings, reminders and deadlines are in one place.',
        ],
        [
            'selector' => '[data-tour="cal-views"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Switch between Month, Week, Day and Agenda.',
            'why' => 'Use Day when you are busy and Month when you plan ahead.',
        ],
        [
            'selector' => '[data-tour="cal-today"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Today to jump back to now.',
            'why' => 'You never get lost in the months.',
        ],
        [
            'selector' => '[data-tour="cal-layers"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Layers choose what shows, like viewings or deadlines.',
            'why' => 'Hide what you do not need so the diary stays clear.',
        ],
        [
            'selector' => '[data-tour="cal-cockpit"] button[data-event-id]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press an event to see who it is with and which house.',
            'why' => 'You have the details before you set off.',
        ],
        [
            'selector' => '[data-tour="cal-deck"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Below the diary are your upcoming events and to-dos.',
            'why' => 'It is your list for today, without opening anything.',
        ],
    ],
    'outro' => 'Next, see how to put an appointment in your diary.',
];
