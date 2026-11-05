<?php

/**
 * Training script - FICA, client side: what the client gets and sees. Runs on the training agency's own FICA link (TrainingAgencySeeder::FICA_LINK_TOKEN, an invented
 * client, never expires). The page needs no sign-in. Nothing is uploaded or submitted: an upload saves the moment a file is picked, so the recording only points at the
 * upload boxes and the Submit button and never uses them.
 */
return [
    'key' => 'fica-client-form',
    'title' => 'What your client sees: the FICA form',
    'route' => 'fica.form',
    'url' => '/fica/bluegum-training-fica-link',
    'specs' => ['.ai/specs/compliance.md'],
    'audience' => [],
    'related' => ['fica-send-request', 'fica-agent-review'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'This is what your client sees when you send them a FICA.',
    'steps' => [
        [
            'selector' => '.fica-card:has(input[name="entity_type"])', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'The client opens the link in their email. They say who is filling it in.',
            'why' => 'A person, a company or a trust each get their own questions.',
        ],
        [
            'selector' => '.fica-card:has([data-qa="fica-id-number"])', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Their details are already filled in from your contact page.',
            'why' => 'They only check and correct, so it takes minutes.',
        ],
        [
            'selector' => 'textarea[name="personal[residential_address]"]', 'action' => 'type', 'done' => 'input', 'value' => '12 Bluegum Road, Margate',
            'narration' => 'They type their home address.',
            'why' => 'It must match the proof of address they upload.',
        ],
        [
            'selector' => '.fica-card:has(textarea[name="service[payment_method]"])', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'They say why they are dealing with you and how they will pay.',
            'why' => 'The law asks where the money comes from.',
        ],
        [
            'selector' => '.fica-card:has(.upload-zone)', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'They add a photo of their ID and a proof of address here.',
            'why' => 'You get the documents with the form, not by email later.',
        ],
        [
            'selector' => '.fica-card:has(#signatureCanvas)', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'They sign with a finger or a mouse.',
            'why' => 'The signature turns the form into their declaration.',
        ],
        [
            'selector' => 'button.fica-btn', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'They press Submit.',
            'why' => 'It lands in your Awaiting Agent Review list straight away.',
        ],
    ],
    'outro' => 'Now you know what to tell your client to expect.',
];
