<?php

/**
 * Training script - Contacts 4 of 4: link a contact to a property. Selectors walked on QA1 as the training agent. Route: none on purpose (the contact page's route belongs to
 * "Everything on a contact's page"; ?flow=link-property keeps this one apart). Nothing is linked: the recording searches and points at the result, and never picks it.
 */
return [
    'key' => 'contacts-link-property',
    'title' => 'Link a contact to a property',
    'url' => '/corex/contacts?flow=link-property',
    'specs' => ['.ai/specs/contacts.md'],
    'audience' => [],
    'related' => ['contacts-find', 'contacts-create', 'contacts-page-tabs'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to link a contact to a property.',
    'steps' => [
        [
            'selector' => 'main a.font-semibold[href*="/corex/contacts/"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the contact.',
            'why' => 'A link is made from the person\'s own page.',
        ],
        [
            'selector' => '#tab-bar button[\@click="activeTab = \'properties\'"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press the Properties tab.',
            'why' => 'It shows the houses already linked to them.',
        ],
        [
            'selector' => '#tab-matches input[type="text"]', 'action' => 'type', 'done' => 'input', 'value' => 'Uvongo',
            'narration' => 'Type the street or suburb of the property.',
            'why' => 'You find it without leaving the page.',
        ],
        [
            'selector' => '#tab-matches input[type="text"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Pick the property from the list that appears.',
            'why' => 'Then CoreX knows this person owns it, or wants it.',
        ],
    ],
    'outro' => 'Linked contacts appear on the property too, so both pages tell the same story.',
];
