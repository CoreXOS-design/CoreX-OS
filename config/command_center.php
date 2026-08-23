<?php

return [

    /*
    |--------------------------------------------------------------------------
    | deal.milestone_due scan (notifications:scan-deals)
    |--------------------------------------------------------------------------
    | Default OFF (2026-08-23). notifications:scan-deals crashed on every
    | scheduled run (every 30 minutes) inside THIS block specifically —
    | CalendarEvent::withoutGlobalScopes() already returns a query builder,
    | and the ->query() that followed it doesn't exist on a builder, only on
    | the model. The two blocks before this one in the same command
    | (deal.stalled_offer/bond/conveyancing, deal.commission_unpaid) run
    | BEFORE the crash point and have been executing and dispatching
    | normally the whole time — this flag does not touch them and does not
    | change what they send.
    |
    | Nobody has ever seen this specific block execute in production, so
    | nobody knows what it actually sends — same reasoning as
    | OVERSIGHT_NUDGES_ENABLED (config/oversight.php), gated rather than
    | switched on the moment the crash was fixed.
    |
    | Unlike the oversight nudge case, this one does not need the more
    | careful "record the fact, suppress only the send" gate: the query only
    | ever considers CalendarEvents dated TODAY or later, filtered further to
    | a rolling per-user threshold window. Leaving this off for any length of
    | time never accumulates a backlog to flood on when switched on — it just
    | starts evaluating that day's qualifying events fresh on the next tick,
    | same as any other 30-minute cycle. There is nothing to catch up on.
    |
    | Volume measured on live before landing the fix (see
    | .ai/audits/2026-08-23-live-error-reduction.md): only 3 users have ever
    | opted into deal.milestone_due (default_enabled=false on the event
    | type, so opt-in only), and of those, exactly 2 notifications would fire
    | on the very first run — a small one-off, not a flood, then a trickle
    | thereafter as new milestones cross into their window.
    */
    'deal_milestone_due_scan_enabled' => (bool) env('DEAL_MILESTONE_DUE_SCAN_ENABLED', false),

];
