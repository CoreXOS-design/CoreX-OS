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

        /*
        |-----------------------------------------------------------------
        | ES-6.7 — AI extraction-fidelity verification (PDF imports only)
        |-----------------------------------------------------------------
        | After a PDF extracts to the CDS shape, an AI vision pass compares
        | the original PDF against the extracted result and flags
        | divergences for human ratification. Severity bands and the
        | divergence-type → severity map are config-driven (not hardcoded),
        | so an agency/environment can re-tune what blocks vs warns.
        */
        'fidelity' => [
            // Master switch. When false, PDF imports skip verification
            // entirely (extraction still works; state recorded as null).
            'enabled' => (bool) env('DOCUPERFECT_IMPORT_FIDELITY_CHECK', true),

            // Model tier for the vision compare ('quality' = Sonnet).
            'model_alias' => env('DOCUPERFECT_IMPORT_FIDELITY_MODEL', 'quality'),

            // Hard cap on flags persisted per import (runaway guard).
            'max_flags' => (int) env('DOCUPERFECT_IMPORT_FIDELITY_MAX_FLAGS', 40),

            // Authoritative divergence-type → severity map. The AI suggests a
            // type + severity; THIS map decides the band (config wins). Unknown
            // types fall to `default_severity` (fail-safe → high = needs review).
            'severity_map' => [
                'missing_clause'   => 'high',
                'dropped_content'  => 'high',
                'reordered'        => 'high',
                'scrambled_order'  => 'high',
                'merged_columns'   => 'high',
                'misplaced_marker' => 'high',
                'mangled_table'    => 'high',
                'mangled_numbers'  => 'high',
                'heading_absorbed' => 'low',
                'lost_linebreaks'  => 'low',
                'whitespace'       => 'low',
                'formatting'       => 'low',
                'minor'            => 'low',
            ],

            // Fail-safe band for an unrecognised divergence type.
            'default_severity' => env('DOCUPERFECT_IMPORT_FIDELITY_DEFAULT_SEVERITY', 'high'),

            // Which severities block wizard use until human-resolved.
            'blocking_severities' => ['high'],
        ],
    ],

];
