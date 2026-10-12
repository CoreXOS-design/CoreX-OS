<?php

/*
|--------------------------------------------------------------------------
| E-sign acknowledgement wording
|--------------------------------------------------------------------------
|
| Shown ONCE, to the admin in template setup, when e-signing is switched ON for a template whose
| document type carries `document_types.esign_warning_required` (sale agreements, offers to
| purchase, deeds). It is a warning, never a block: the agency decides. It is never shown to an
| agent anywhere. Wording is agency-neutral.
|
| DRAFT WORDING — pending Johan's approval. Bump `version` whenever the wording changes so the
| audit trail (which stores the exact text acknowledged) shows which version was shown.
*/

return [
    'version' => 1,

    'title' => 'Before you switch e-signing on for this document',

    'paragraphs' => [
        'South African law (the Electronic Communications and Transactions Act, ECTA) currently does not recognise an electronically signed agreement for the sale of immovable property. A sale of land that is signed electronically may not be legally valid.',
        'Switching e-signing on for this template is your agency\'s own decision, and the risk is your agency\'s own. CoreX will not stop you.',
        'We strongly recommend that you take legal advice before you use e-signing for sale agreements, offers to purchase or deeds.',
    ],

    'confirm_label' => 'I understand. I am switching e-signing on for this template as my agency\'s decision.',
    'enable_button' => 'Switch e-signing on',
    'cancel_button' => 'Cancel - keep wet ink',

    // Admin-only record line, shown inside template setup (manage_templates) and nowhere an
    // agent sees: not on the send-for-signature screen, the wizard, documents, mail or signing page.
    'record' => 'E-signing switched on by :name on :date after acknowledging the legal warning',

    // Shown when the acknowledgement is missing on a save.
    'required_message' => 'E-signing for this type of document needs your acknowledgement of a legal warning before it can be switched on.',
];
