<?php

/**
 * Training script - Contacts 3 of 4: the contact's own page and its tabs. Selectors walked on QA1 as the training agent. The tab buttons carry no stable attribute, so they
 * are found by what they do (button[\@click="activeTab = '...'"]). Read-only: nothing is typed or saved.
 */
return [
    'key' => 'contacts-page-tabs',
    'title' => 'Everything on a contact\'s page',
    'route' => 'corex.contacts.show',
    'url' => '/corex/contacts?flow=page-tabs',
    'specs' => ['.ai/specs/contacts.md'],
    'audience' => [],
    'related' => ['contacts-find', 'contacts-create', 'contacts-link-property'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is everything you can find on a contact\'s page.',
    'steps' => [
        [
            'selector' => 'main a.font-semibold[href*="/corex/contacts/"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open a contact from your list.',
            'why' => 'Every call, email and viewing with them is on one page.',
        ],
        [
            'selector' => '#tab-bar', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'These tabs hold everything about this person.',
            'why' => 'You never have to search five screens before you phone them.',
        ],
        [
            'selector' => '#tab-bar button[\@click="activeTab = \'properties\'"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Properties shows the houses they own or want.',
            'why' => 'Buyers get matched to houses from here.',
        ],
        [
            'selector' => '#tab-bar button[\@click="activeTab = \'viewings\'"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Viewings shows every viewing and what they said.',
            'why' => 'You know how keen they are before you call.',
        ],
        [
            'selector' => '#tab-bar button[\@click="activeTab = \'fica\'"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'FICA shows if they have been checked.',
            'why' => 'You can start a FICA from here too.',
        ],
        [
            'selector' => '#tab-bar button[\@click="activeTab = \'history\'"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'History shows everything that happened, in order.',
            'why' => 'A colleague can pick up the conversation without asking you.',
        ],
    ],
    'outro' => 'Next, see how to link a contact to a property.',
];
