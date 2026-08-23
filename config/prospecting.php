<?php

declare(strict_types=1);

/**
 * Prospecting / Market Intelligence runtime configuration.
 *
 * Lives in /config so engineering-level behaviour toggles for this module
 * don't require a code change to flip. Read via config('prospecting.…').
 */

return [

    /*
    |---------------------------------------------------------------------
    | MIC speed round 4 — suburb-sort tie-break
    |---------------------------------------------------------------------
    | .ai/specs/mic-speed-option1-full-set-pagination-design.md.
    |
    | The Work-tab's "By suburb" sort (?sort=suburb) currently ties-breaks
    | via whatever order MySQL's filesort happens to return for equal
    | suburb values — undocumented, not based on anything an agent could
    | reason about, and not guaranteed to survive a MySQL upgrade or an
    | index change (proven empirically 2026-08-23: two rows with the
    | byte-identical suburb "Albersville" sort in a different relative
    | order than their ids, under a plan that gives every OTHER allowed
    | sort column - last_seen_at, first_seen_at, price - a clean,
    | reproducible `id ASC` tie-break).
    |
    | OFF (default) — suburb sort keeps today's exact order, via the
    | existing full-hydration code path (same one used for the score-band,
    | buyer-mode, and stock-audit-toggle cases). Byte-identical to
    | pre-rewrite behaviour, ~11-15s on the current data volume.
    |
    | ON — suburb sort runs through the fast, SQL-side paginated path with
    | an explicit `ORDER BY suburb <dir>, id ASC` tie-break. Deterministic
    | and reproducible going forward, under 2s — but rows that share the
    | exact same suburb name will show in a DIFFERENT relative order than
    | they do today, once, the first time this flag is turned on. Nothing
    | about which suburb a row is grouped under changes; only the order
    | WITHIN a tied suburb value.
    |
    | This is a one-time, Johan-only product decision (not agency-
    | configurable, not a Setup Wizard entry) - today's order encodes
    | nothing meaningful, so flipping it isn't "wrong→right", it's
    | "one arbitrary order→a different, more durable one". Left OFF until
    | he's made that call with the evidence in front of him.
    */
    'suburb_sort_explicit_tiebreak' => (bool) env('PROSPECTING_SUBURB_SORT_EXPLICIT_TIEBREAK', false),

];
