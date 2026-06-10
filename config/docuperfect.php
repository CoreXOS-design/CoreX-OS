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
                'title'   => 'Flag any concerns',
                'body'    => 'Hover any clause to flag a change. The agent reviews flags before final sign-off, so nothing leaves you uncomfortable.',
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

    /*
    |---------------------------------------------------------------------
    | Document import (ES-6) — CDS import tunables
    |---------------------------------------------------------------------
    | The CDS import path (/import/cds) accepts Word and text-based PDF.
    | All limits are env-overridable so an agency/environment can tune them
    | without code changes. No hardcoded thresholds in the parser.
    */
    'import' => [
        // Accepted upload extensions for the CDS import path.
        'allowed_extensions' => ['docx', 'pdf'],

        // Max upload size in kilobytes (Laravel `max:` rule unit). Default 20MB.
        'max_upload_kb' => (int) env('DOCUPERFECT_IMPORT_MAX_KB', 20480),

        // A PDF whose extractable text (trimmed) is below this character count
        // is treated as image-only / scanned. Faithful OCR import of scanned
        // legal documents is a documented deferral (fidelity-sensitive); such
        // a PDF is rejected with guidance rather than producing a low-fidelity
        // template. Tunable per environment.
        'min_pdf_text_chars' => (int) env('DOCUPERFECT_IMPORT_MIN_PDF_TEXT_CHARS', 120),
    ],

];
