<?php

/**
 * Training script - Contacts 1 of 4: the contact list and finding someone. Selectors walked on QA1 as the training agent. The training agency's contacts are invented
 * (TrainingAgencySeeder) and belong to the training agent, so the list is never empty.
 */
return [
    'key' => 'contacts-find',
    'title' => 'Find a contact',
    'route' => 'corex.contacts.index',
    'url' => '/corex/contacts',
    'specs' => ['.ai/specs/contacts.md'],
    'audience' => [],
    'related' => ['contacts-create', 'contacts-page-tabs', 'contacts-link-property'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to find a contact.',
    'steps' => [
        [
            'selector' => '[data-tour="contact-search"]', 'action' => 'type', 'done' => 'input', 'value' => 'Bianca',
            'narration' => 'Type a name, a phone number or an email.',
            'why' => 'You find the person while they are still on the phone.',
        ],
        [
            'selector' => 'select[name="type"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Or choose a type, like Buyer or Seller.',
            'why' => 'You can phone every buyer in one go.',
        ],
        [
            'selector' => '[data-tour="contact-street-search"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'This button finds everyone who lives in a street or complex.',
            'why' => 'Good for letting a whole complex know about a sale.',
        ],
        [
            'selector' => 'main a[href*="tab=fica"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Each contact shows how far their FICA has got.',
            'why' => 'You see who still needs to be checked before a deal.',
        ],
    ],
    'outro' => 'Next, see how to add a new contact.',
];
