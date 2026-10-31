<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'role-manager-create-role',
    'title' => 'Create a role and give it to a person',
    'route' => 'corex.role-manager',
    'url' => '/corex/role-manager',
    'specs' => ['.ai/specs/roles-permissions.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Here is how to create a role and give it to someone on your team.',
    'steps' => [
        [
            'selector' => '[data-tour="rm-tab-roles"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open the Roles tab.',
            'why' => 'All your roles are listed here.',
        ],
        [
            'selector' => '[data-tour="rm-add-role"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Press Add New Role.',
            'why' => 'A role is a set of rights for one kind of person, like an office admin.',
        ],
        [
            'selector' => '[data-tour="rm-role-label"]',
            'action' => 'type',
            'done' => 'input',
            'value' => 'Office Admin',
            'wait_for' => '[data-tour="rm-role-label"]',
            'narration' => 'Type a name for the role.',
            'why' => 'You pick people\'s roles by this name.',
        ],
        [
            'selector' => '[data-tour="rm-role-save"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Press Create Role.',
            'why' => 'The role then shows in every list in CoreX.',
        ],
        [
            'selector' => '[data-tour="rm-tab-permissions"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Now open the Permissions tab.',
            'why' => 'This is where you choose what each role may do.',
        ],
        [
            'selector' => '[data-tour="rm-role-pills"]',
            'action' => 'highlight',
            'done' => 'none',
            'wait_for' => '[data-tour="rm-role-pills"]',
            'narration' => 'Pick the role you want to change.',
            'why' => 'A change only applies to the role you pick.',
        ],
        [
            'selector' => '[data-tour="rm-matrix"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Tick what this role may see and do. It saves by itself.',
            'why' => 'Give only what the person needs, so private things stay private.',
        ],
        [
            'selector' => '[data-tour="rm-tab-users"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Open the User Roles tab.',
            'why' => 'This is where you give people their role.',
        ],
        [
            'selector' => '.user-role-form select[name="role"]',
            'action' => 'select',
            'done' => 'change',
            'value' => 'office_admin',
            'wait_for' => '.user-role-form select[name="role"]',
            'narration' => 'Choose the person\'s role in the list next to their name.',
            'why' => 'Their screens then change to match the role.',
        ],
        [
            'selector' => '.user-role-form button[type="submit"]',
            'action' => 'click',
            'done' => 'click',
            'narration' => 'Press Save next to their name.',
            'why' => 'The change applies to that person only.',
        ],
    ],
    'outro' => 'That person now sees only what their role allows.',
];
