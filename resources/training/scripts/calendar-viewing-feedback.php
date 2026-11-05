<?php

/**
 * Training script - Calendar 3 of 3: capture feedback after a viewing. Selectors walked on QA1 as the training agent (the training diary has a viewing that already
 * happened - TrainingAgencySeeder). Route: none on purpose (the diary's route belongs to the approved "Book a viewing in the diary" video; ?flow=feedback keeps this one
 * apart). Nothing is saved: the recording points at the fields and closes the box with Cancel.
 */
return [
    'key' => 'calendar-viewing-feedback',
    'title' => 'Add feedback after a viewing',
    'url' => '/corex/command-center/calendar?flow=feedback',
    'specs' => ['.ai/specs/calendar-viewing-feedback.md'],
    'audience' => [],
    'related' => ['book-a-viewing', 'calendar-view-diary', 'calendar-book-appointment'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to add feedback after a viewing.',
    'steps' => [
        [
            'selector' => '[data-tour="cal-cockpit"] button[data-event-id]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press the viewing in your diary.',
            'why' => 'Feedback belongs to the viewing, so start there.',
        ],
        [
            'selector' => '[data-tour="cal-panel"] button[\@click="openFeedbackModal(panelData.id)"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press Capture Feedback.',
            'why' => 'CoreX asks you for it, so the seller is never left wondering.',
        ],
        [
            'selector' => '[data-qa="feedback-panel"] select', 'action' => 'highlight', 'done' => 'none', 'wait_for' => '[data-qa="feedback-panel"] select',
            'narration' => 'Say if the buyer came, and what they thought.',
            'why' => 'It feeds your buyer matches, so you learn what they really want.',
        ],
        [
            'selector' => '[data-qa="feedback-panel"] textarea', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Write what the seller may read. The buyer\'s name is never shown.',
            'why' => 'Sellers see honest feedback on their live link, and trust you more.',
        ],
        [
            'selector' => '[data-qa="feedback-save"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Save Feedback.',
            'why' => 'It also shows on the buyer\'s page and on the property.',
        ],
        [
            'selector' => '[data-qa="feedback-cancel"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press Cancel if you are not ready.',
            'why' => 'Nothing is kept until you save.',
        ],
    ],
    'outro' => 'That is how every viewing turns into something useful.',
];
