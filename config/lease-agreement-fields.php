<?php

/*
|--------------------------------------------------------------------------
| Lease agreement field registry
|--------------------------------------------------------------------------
|
| .ai/specs/leases.md §15.7.1 / §15.8.3 / §15.12.5 (Build L1 — foundation).
|
| The single VOCABULARY of agreement fields CoreX knows how to capture, carry into an agency's own
| lease agreement and compare back. It says nothing about any particular agreement: the mapping from
| this vocabulary to a particular lease agreement's own field names is stored per agency, per linked
| agreement, in `rental_lease_templates.field_map` (set up by the agency under Settings → Rental
| lease agreements). A new agency's lease needs a map, not code.
|
| MULTI-AGENCY (CLAUDE.md #9): every key and label here is neutral. Nothing in this file names an
| agency, a fee scheme or a document. A fee/schedule field exists only for an agency whose own map
| carries it, and its on-screen label comes from that map (`label`), never from here.
|
| Entry shape
|   label        plain-English default label (a map entry may override it)
|   type         money | date | integer | percent | month | text | longtext
|   group        which section of the capture screen: lease | parties | term | agreement | notice | schedule | calculated
|   side         where the CoreX-side value lives:
|                  lease      → a column of `leases`            (column)
|                  contact    → the tenant/landlord contact     (contact)
|                  terms      → a column of `lease_agreement_terms` (column) — or `extra` when no column
|                  calculated → recomputed from other fields, never typed
|   column       the column name on `leases` / `lease_agreement_terms` (side lease|terms)
|   comparison   how the document's printed value is compared with the CoreX side (§15.8.3):
|                  money (to the cent) | date | name | text | number | calculated
|   accept       whether a difference may be ACCEPTED from the document at confirm time (§15.8.3).
|                A different person (name, contact keys) is never acceptable — it is a new lease (R7).
|   indexed      the key stands for a family `<key>_1 … <key>_n` as well as the combined `<key>`
|
| A key that appears in an agency's field map but not here is an agency-specific extra: it is read as
| plain text, compared as text, accepted from the document, and stored in `lease_agreement_terms.extra`.
*/

return [

    'fields' => [

        // ── From the lease record ────────────────────────────────────────────────
        'rent' => [
            'label' => 'Monthly rent', 'type' => 'money', 'group' => 'lease',
            'side' => 'lease', 'column' => 'rental_amount', 'comparison' => 'money', 'accept' => true,
        ],
        'start_date' => [
            'label' => 'Start date', 'type' => 'date', 'group' => 'lease',
            'side' => 'lease', 'column' => 'start_date', 'comparison' => 'date', 'accept' => true,
        ],
        'end_date' => [
            'label' => 'End date', 'type' => 'date', 'group' => 'lease',
            'side' => 'lease', 'column' => 'end_date', 'comparison' => 'date', 'accept' => true,
        ],
        'deposit' => [
            'label' => 'Deposit', 'type' => 'money', 'group' => 'lease',
            'side' => 'lease', 'column' => 'deposit_amount', 'comparison' => 'money', 'accept' => true,
        ],

        // ── Parties (from the contact records; never acceptable from the document) ──
        'tenant_name' => [
            'label' => 'Tenant name', 'type' => 'text', 'group' => 'parties',
            'side' => 'contact', 'comparison' => 'name', 'accept' => false, 'indexed' => true,
        ],
        'landlord_name' => [
            'label' => 'Landlord name', 'type' => 'text', 'group' => 'parties',
            'side' => 'contact', 'comparison' => 'name', 'accept' => false, 'indexed' => true,
        ],
        'tenant_address' => [
            'label' => 'Tenant address', 'type' => 'text', 'group' => 'parties',
            'side' => 'contact', 'comparison' => 'text', 'accept' => false,
        ],
        'tenant_id' => [
            'label' => 'Tenant ID / passport number', 'type' => 'text', 'group' => 'parties',
            'side' => 'contact', 'comparison' => 'text', 'accept' => false,
        ],
        'landlord_address' => [
            'label' => 'Landlord address', 'type' => 'text', 'group' => 'parties',
            'side' => 'contact', 'comparison' => 'text', 'accept' => false,
        ],
        'landlord_id' => [
            'label' => 'Landlord ID / passport / registration number', 'type' => 'text', 'group' => 'parties',
            'side' => 'contact', 'comparison' => 'text', 'accept' => false,
        ],

        // ── Agreement terms (typed on the capture screen, stored in lease_agreement_terms) ──
        'adults' => [
            'label' => 'Number of adults', 'type' => 'integer', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'adults', 'comparison' => 'number', 'accept' => true,
        ],
        'max_other_persons' => [
            'label' => 'Maximum other persons', 'type' => 'integer', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'max_other_persons', 'comparison' => 'number', 'accept' => true,
        ],
        'pets' => [
            'label' => 'Pets allowed (type and number)', 'type' => 'text', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'pets', 'comparison' => 'text', 'accept' => true,
        ],
        'escalation_percent' => [
            'label' => 'Yearly escalation (%)', 'type' => 'percent', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'escalation_percent', 'comparison' => 'number', 'accept' => true,
        ],
        'escalation_month' => [
            'label' => 'Escalation month', 'type' => 'month', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'escalation_month', 'comparison' => 'text', 'accept' => true,
        ],
        'earliest_termination_date' => [
            'label' => 'Earliest date notice may expire', 'type' => 'date', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'earliest_termination_date', 'comparison' => 'date', 'accept' => true,
        ],
        'renewal_option_months' => [
            'label' => 'Renewal period (months)', 'type' => 'integer', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'renewal_option_months', 'comparison' => 'number', 'accept' => true,
        ],
        'electricity_arrangement' => [
            'label' => 'Electricity / utilities arrangement', 'type' => 'text', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'electricity_arrangement', 'comparison' => 'text', 'accept' => true,
        ],
        'other_conditions' => [
            'label' => 'Other conditions', 'type' => 'longtext', 'group' => 'agreement',
            'side' => 'terms', 'column' => 'other_conditions', 'comparison' => 'text', 'accept' => true,
        ],

        // ── Notice and early-cancellation terms (leases.md §18) ────────────────────────────────
        // Always captured on the capture / renewal / lease screens (their own block, not the map-driven agreement section);
        // they reach the lease agreement DOCUMENT only when the agency's own map carries them, and when it does, the document,
        // the lease record and the portal FAQ read the SAME columns — so they cannot disagree.
        'notice_period' => [
            'label' => 'Notice period', 'type' => 'integer', 'group' => 'notice',
            'side' => 'terms', 'column' => 'notice_period', 'comparison' => 'number', 'accept' => true,
        ],
        'notice_period_unit' => [
            'label' => 'Notice period unit (days, weeks or months)', 'type' => 'text', 'group' => 'notice',
            'side' => 'terms', 'column' => 'notice_period_unit', 'comparison' => 'text', 'accept' => true,
        ],
        'earliest_notice_date' => [
            'label' => 'Earliest date notice may be given', 'type' => 'date', 'group' => 'notice',
            'side' => 'terms', 'column' => 'earliest_notice_date', 'comparison' => 'date', 'accept' => true,
        ],
        'early_cancellation_allowed' => [
            'label' => 'Early cancellation allowed (yes or no)', 'type' => 'text', 'group' => 'notice',
            'side' => 'terms', 'column' => 'early_cancellation_allowed', 'comparison' => 'text', 'accept' => true,
        ],
        'early_cancellation_notice' => [
            'label' => 'Notice needed to cancel early', 'type' => 'integer', 'group' => 'notice',
            'side' => 'terms', 'column' => 'early_cancellation_notice', 'comparison' => 'number', 'accept' => true,
        ],
        'early_cancellation_notice_unit' => [
            'label' => 'Early-cancellation notice unit (days, weeks or months)', 'type' => 'text', 'group' => 'notice',
            'side' => 'terms', 'column' => 'early_cancellation_notice_unit', 'comparison' => 'text', 'accept' => true,
        ],
        'early_cancellation_penalty' => [
            'label' => 'Early-cancellation penalty (wording)', 'type' => 'longtext', 'group' => 'notice',
            'side' => 'terms', 'column' => 'early_cancellation_penalty', 'comparison' => 'text', 'accept' => true,
        ],

        // ── Schedule values — exist only for an agency whose own map carries them (§15.12.5) ──
        // Kept in lease_agreement_terms.extra; neutral keys, the label comes from the agency's own map.
        'other_deduction' => [
            'label' => 'Other deduction', 'type' => 'money', 'group' => 'schedule',
            'side' => 'terms', 'column' => null, 'comparison' => 'money', 'accept' => true,
        ],

        // ── Calculated (recomputed from the figures being confirmed; informational) ──
        'property_description' => [
            'label' => 'Property description', 'type' => 'text', 'group' => 'calculated',
            'side' => 'calculated', 'comparison' => 'calculated', 'accept' => false,
        ],
        'rent_in_words' => [
            'label' => 'Rent in words', 'type' => 'text', 'group' => 'calculated',
            'side' => 'calculated', 'comparison' => 'calculated', 'accept' => false,
        ],
        'escalation_in_words' => [
            'label' => 'Escalation in words', 'type' => 'text', 'group' => 'calculated',
            'side' => 'calculated', 'comparison' => 'calculated', 'accept' => false,
        ],
        'agent_service_fee' => [
            'label' => 'Agent service fee', 'type' => 'money', 'group' => 'calculated',
            'side' => 'calculated', 'comparison' => 'calculated', 'accept' => false,
        ],
        'net_to_owner' => [
            'label' => 'Net amount to owner', 'type' => 'money', 'group' => 'calculated',
            'side' => 'calculated', 'comparison' => 'calculated', 'accept' => false,
        ],
    ],

    /*
    | What a field map must carry before the agreement is LINKABLE (§15.12.3). Read by
    | LeaseAgreementTemplateGuard (Build L0). `all` keys must be mapped; each `any_of` group needs at
    | least one of its keys; `end_date` is required unless the agreement is month-to-month capable
    | (§15.12.3 — how a template shows that is Build L0's to define, nothing here assumes it).
    */
    'link_requirements' => [
        'all' => ['rent', 'start_date'],
        'any_of' => [
            ['tenant_name', 'tenant_name_1'],
            ['landlord_name', 'landlord_name_1'],
        ],
        'end_date_unless_month_to_month' => true,
    ],

    /*
    | Which map keys an agency may mark "required for signing". Money/date/party keys that come from
    | the lease record or the contacts are always needed for the document; only the agreement-term and
    | schedule keys are the agency's call (§15.12.3).
    */
    'requirable_groups' => ['agreement', 'schedule', 'notice'],
];
