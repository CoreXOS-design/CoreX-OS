<?php

/**
 * AT-158 Deal Register V2 — programme config.
 *
 * WS6 (notifications & escalation). Every threshold, recipient, and channel is
 * config-driven (non-negotiable: no hardcoded process). A pipeline step may
 * override the escalation ladder via its own `escalation_config` JSON; this file
 * is the SENSIBLE DEFAULT applied when the step has none.
 */
return [

    'escalation' => [

        /*
         * The default overdue-escalation ladder. Each rung fires ONCE, in order,
         * when the step has been overdue for at least `days_overdue` days, to the
         * role's recipients. A step's own `escalation_config['levels']` overrides
         * this list wholesale. A rung is still gated by the step's notify_* flag
         * for that role (notify_bm / notify_admin), so an agency can silence a
         * whole rung per step without editing the ladder.
         */
        'levels' => [
            ['role' => 'branch_manager', 'days_overdue' => 2],
            ['role' => 'admin',          'days_overdue' => 5],
        ],

        // Channels used for escalation notifications (still AND-gated by each
        // recipient's own notification preferences).
        'channels' => ['in_app', 'email'],
    ],

    'digest' => [
        // Per-user morning digest send time (server tz). Scheduled in routes/console.php.
        'time' => env('DEALS_DIGEST_TIME', '07:00'),
    ],

    /*
     * WS "complete-with-reason" (anti-gaming escape valve). When a step is
     * marked complete WITHOUT meeting its normal requirement (e.g. a doc-bearing
     * step with no document), a structured reason is required. These are the
     * common-reason dropdown options (agency-configurable; "Other" always allows
     * freetext). Config-driven — never hardcoded in the view/controller.
     */
    'completion' => [
        'override_reasons' => [
            'document_filed_elsewhere' => 'Document already filed elsewhere',
            'confirmed_verbally'       => 'Confirmed verbally / by email',
            'client_provided_offline'  => 'Client provided outside CoreX',
            'not_applicable'           => 'Not applicable to this deal',
            'other'                    => 'Other (explain)',
        ],
    ],
];
