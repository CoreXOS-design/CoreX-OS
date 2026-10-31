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
    | Required types that do NOT apply to a RENTAL mandate
    |--------------------------------------------------------------------------
    |
    | The Mandatory Disclosure Form (slug 'disclosure') is the SELLER's disclosure of the condition of a property being SOLD. A
    | landlord letting a property gives no such disclosure, so a rental is never blocked on it (Sherry walk, 13 Oct 2026). Kept for a
    | rental: the mandate (the letting mandate), FICA on the landlord (compliance.md: FICA applies to the landlord and the tenant),
    | the photos and the property details. Matched against document_types.slug.
    |
    */
    'rental_not_required_slugs' => ['disclosure'],

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

    /*
    |--------------------------------------------------------------------------
    | Compliance Switches disclaimer (AT-509)
    |--------------------------------------------------------------------------
    |
    | Shown before an agency switches any property compliance check off
    | (.ai/specs/property-compliance-gate-settings.md §8, wording approved by
    | Andre 2026-10-26). System-owned, NOT agency-editable: it protects CoreX.
    | {agency} = the agency's own name, {gates} = the checks being switched off.
    |
    | Changing the wording means bumping the version. Every history row keeps
    | the exact rendered text that was accepted, so old acceptances never change.
    |
    */

    'gate_disclaimer' => [
        'version' => '2026-10-26.1',
        'text' => "You are switching off the following compliance check(s) for {agency}: {gates}.\n\n"
            . "While a check is off, CoreX will allow listings to be marketed without verifying it. By confirming, {agency} acknowledges that:\n\n"
            . "1. {agency} remains solely responsible for its compliance obligations under the Financial Intelligence Centre Act 38 of 2001 (FICA), "
            . "the Protection of Personal Information Act 4 of 2013 (POPIA), the Property Practitioners Act 22 of 2019 and the rules of the "
            . "Property Practitioners Regulatory Authority (PPRA), and any other applicable law.\n\n"
            . "2. CoreX OS provides these checks as a tool only. Switching a check off is the agency's own decision, and CoreX OS accepts no "
            . "liability for any non-compliance, penalty, loss or claim arising from listings marketed while it is off.\n\n"
            . "3. This decision, with your name, the date and time, and your IP address, is permanently recorded.",
        'accept_label' => 'I have read and accept this on behalf of {agency}.',
    ],

];
