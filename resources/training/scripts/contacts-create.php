<?php

/**
 * Training script - Contacts 2 of 4: add a new contact. Selectors walked on QA1 as the training agent. Route: none on purpose (the contacts list's route belongs to "Find a
 * contact"; ?flow=create keeps this one apart). Nothing is saved: the recording fills in invented details, points at Save Contact and presses Cancel.
 */
return [
    'key' => 'contacts-create',
    'title' => 'Add a new contact',
    'url' => '/corex/contacts?flow=create',
    'specs' => ['.ai/specs/contacts.md'],
    'audience' => [],
    'related' => ['contacts-find', 'contacts-page-tabs', 'contacts-link-property'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to add a new contact.',
    'steps' => [
        [
            'selector' => '[data-tour="contact-add-btn"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press Add Contact.',
            'why' => 'Every client you meet should be in CoreX, so nobody gets lost.',
        ],
        [
            'selector' => '[data-tour="contact-first-name"]', 'action' => 'type', 'done' => 'input', 'value' => 'Thandi', 'wait_for' => '[data-tour="contact-first-name"]',
            'narration' => 'Type their first name.',
            'why' => 'CoreX uses it to greet them in emails.',
        ],
        [
            'selector' => '[data-tour="contact-last-name"]', 'action' => 'type', 'done' => 'input', 'value' => 'Dlamini',
            'narration' => 'And their surname.',
            'why' => 'CoreX warns you if this person is already in your list.',
        ],
        [
            'selector' => 'input[name="phones[0][value]"]', 'action' => 'type', 'done' => 'input', 'value' => '082 555 0123',
            'narration' => 'Add a phone number.',
            'why' => 'It is how you reach them, and how WhatsApp messages find them.',
        ],
        [
            'selector' => '[data-tour="contact-type"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Choose what they are, like Buyer or Seller. You can pick more than one.',
            'why' => 'It decides which lists and matches they appear in.',
        ],
        [
            'selector' => '[data-tour="contact-save"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Save Contact.',
            'why' => 'Then you can link them to a property and start a FICA.',
        ],
        [
            'selector' => 'button[\@click="showAdd = false"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press Cancel if you do not want to save.',
            'why' => 'Nothing is kept until you press Save.',
        ],
    ],
    'outro' => 'Next, see what is on a contact\'s page.',
];
