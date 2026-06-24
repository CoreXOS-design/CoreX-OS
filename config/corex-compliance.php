<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing-compliance required document types
    |--------------------------------------------------------------------------
    |
    | The property marketing-compliance gate (App\Services\Compliance\
    | MarketingReadinessService) reads each agency's CONFIGURABLE required
    | document-type list — NOT hardcoded strings. The list lives per-agency in
    | the `agency_document_type_compliance` pivot, editable at
    | Settings -> Document Types.
    |
    | These are the DEFAULTS a new (or freshly-initialised) agency starts with.
    | They are matched against `document_types.slug`. Any slug that does not
    | exist for the install is simply skipped — never an error.
    |
    | Doctrine (standing, all agencies): a typed Drive document being present
    | IS the gate. No approval/verification status is checked on the Drive
    | path — a wet-ink/physical document is signed off physically by the BM
    | before it is scanned and uploaded, so the control is already met. The
    | system-side approval control lives only in the e-sign pipeline, which is
    | untouched. Instant-unlock on typed upload is the digital reflection of a
    | completed physical sign-off, not a lowered bar.
    |
    */

    'default_required_slugs' => [
        'mandate',     // Authority to Market
        'fica',        // FICA customer due diligence
        'disclosure',  // Mandatory Disclosure Form (MDF)
    ],

    /*
    |--------------------------------------------------------------------------
    | FICA document-type slug
    |--------------------------------------------------------------------------
    |
    | When this type is among an agency's required types, the gate accepts
    | EITHER a typed FICA document present on the Drive (property or seller
    | contact) OR the authoritative FICA workflow (all sellers `approved` in
    | `fica_submissions`). This preserves the existing FICA source of truth
    | while honouring the instant-unlock-on-typed-upload doctrine.
    |
    */

    'fica_slug' => 'fica',

];
