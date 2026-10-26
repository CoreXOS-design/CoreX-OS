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
        // First-time signer flow (esign-signing-modes.md §13): three steps, one sentence each. `body` is the text for an
        // agency whose signers save a signature and initials once; `body_each` for one whose signers sign each spot by hand.
        'heading' => 'Your three steps',
        'steps' => [
            [
                'title'     => 'Add your signature and initials',
                'title_each' => "How you'll sign",
                'body'      => 'Draw or type them once. CoreX puts them where you need to sign.',
                'body_each' => "You'll sign each spot by hand as you go.",
            ],
            [
                'title'     => 'Check your details',
                'body'      => 'Everything we know is filled in. Type in anything that is missing.',
            ],
            [
                'title'     => 'Sign',
                'body'      => 'Press Next to go to each spot, or Sign everything to do them all at once.',
                'body_each' => 'Press Next to go to each spot, then sign it.',
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Async completion (post-signing cascade)
    |---------------------------------------------------------------------
    | Default OFF. When enabled, SignatureService::completeDocument() enqueues
    | FinalizeSignedDocumentJob (PDF generation, contact linking, auto-filing,
    | completion emails, lease extraction) instead of running that cascade
    | inline on the last signer's request. The status write, audit log, and
    | document seal remain synchronous either way — those are the durable
    | legal record and are never deferred.
    |
    | async_completion_pdf_sync: when true (and async_completion is also
    | true), PDF generation stays synchronous — run inline, same as legacy
    | behaviour — and only linking/filing/emailing/lease-extraction are
    | queued. Exists so "the sealed PDF must exist at the instant of
    | completion" can be restored with a config change alone, no code
    | change, no redeploy of logic — only ever a settings flip.
    */
    'async_completion' => (bool) env('DOCUPERFECT_ASYNC_COMPLETION', false),
    'async_completion_pdf_sync' => (bool) env('DOCUPERFECT_ASYNC_COMPLETION_PDF_SYNC', false),

];
