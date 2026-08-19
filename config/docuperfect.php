<?php

declare(strict_types=1);

/**
 * DocuPerfect runtime configuration.
 *
 * Lives in /config so values can be overridden per-environment or
 * agency-customised without touching code (localisation, A/B copy,
 * etc.). Read via config('docuperfect.…').
 */

return [

    /*
    |---------------------------------------------------------------------
    | Signing guidance — left info-panel content (B3)
    |---------------------------------------------------------------------
    | Five plain-language steps shown to recipients on the signing page,
    | persistent left rail on desktop, collapsed banner on tablet/mobile.
    | Future: per-agency override, per-locale variants.
    */
    'signing_guidance' => [
        'heading' => 'How to sign',
        'steps' => [
            [
                'title'   => 'Review the document',
                'body'    => 'Read through the agreement on the right. Everything is laid out the way you would see it on paper.',
            ],
            [
                'title'   => 'Fill in your fields',
                'body'    => 'Any field highlighted in your colour is yours to complete. Locked fields belong to other parties — you cannot edit those.',
            ],
            [
                'title'   => 'Amend the wording',
                'body'    => 'Not happy with something? Highlight any word, phrase or clause, then click "Amend highlighted text" to strike it out or reword it. Your change is marked on the document and every party initials it before final sign-off — nothing is final until everyone agrees.',
            ],
            [
                'title'   => 'Initial each page',
                'body'    => 'Tap the initial slot at the bottom-right of every page to confirm you have read it.',
            ],
            [
                'title'   => 'Sign at the bottom',
                'body'    => 'Once every field and initial is in place, hit the signature block to apply your signature electronically.',
            ],
        ],

        'help_heading' => 'Need help?',
        'help_intro'   => 'Call the agent who sent this document. They can walk you through anything that is unclear.',
    ],

];
