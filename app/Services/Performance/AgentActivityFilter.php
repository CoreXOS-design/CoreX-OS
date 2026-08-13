<?php

namespace App\Services\Performance;

use Illuminate\Database\Query\Builder;

/**
 * AT-366 CORRECTNESS (2026-08) — genuine agent activity, not the bulk import.
 *
 * A Performance & ROI figure must reflect work the agent actually did on CoreX.
 * The 2026-06 data migration bulk-loaded ~7,700 contacts and ~4,600 historical
 * properties; counting those as "contacts created" / "properties created" inflates
 * long-period figures ~5-13x. This filter counts a record ONLY if it is:
 *     (a) NATIVE  — created in CoreX, not imported, OR
 *     (b) WORKED  — imported BUT the agent has worked it on CoreX since.
 *
 * IMPORT MARKER
 *   contacts.loaded_at IS NOT NULL  → imported (source-carried).  NULL → native capture.
 *   properties (no loaded_at)       → historical import identified by the ABSENCE of any
 *                                     CoreX engagement signal (see propertiesWorked()).
 *
 * "WORKED ON COREX" — signals verified against the live 2026-06 import batch to carry
 * ~0% import contamination. Deliberately EXCLUDED as contaminated (the import stamps/
 * carries them): updated_at (100%), contact_notes (99.9%), and last_contacted_at /
 * loaded_at (source-carried historical dates spanning 2025-2026).
 *   contact:  contacted_marked_at | buyer_pipeline_entered_at | last_activity_at
 *             | messaging_opted_in_at | a seller_outreach_sends row
 *   property: p24_last_submitted_at | pp_last_submitted_at | last_activity_at
 *             | live status (active/to_let/under_offer/draft) | a property_notes row
 *
 * config('performance.count_untouched_imports')=true restores raw counts for audit.
 */
class AgentActivityFilter
{
    /** @return string[] contact "worked on CoreX" own-column signals (uncontaminated). */
    public const CONTACT_WORKED_COLUMNS = [
        'contacted_marked_at',
        'buyer_pipeline_entered_at',
        'last_activity_at',
        'messaging_opted_in_at',
    ];

    /**
     * @return string[] property "worked on CoreX" own-column signals.
     * NOTE: ad_last_generated_at is deliberately NOT used — it exists only on the
     * live DB (schema drift, absent from QA1/snapshot/migrations); the canonical
     * syndication + activity signals below are sufficient and portable.
     */
    public const PROPERTY_WORKED_COLUMNS = [
        'p24_last_submitted_at',
        'pp_last_submitted_at',
        'last_activity_at',
    ];

    public const PROPERTY_LIVE_STATUSES = ['active', 'to_let', 'under_offer', 'draft'];

    public static function countingRaw(): bool
    {
        return (bool) config('performance.count_untouched_imports', false);
    }

    /** Contact counts if native (loaded_at NULL) OR worked-since-import. */
    public static function contacts(Builder $q): Builder
    {
        if (self::countingRaw()) {
            return $q;
        }

        return $q->where(function (Builder $w) {
            $w->whereNull('contacts.loaded_at');
            foreach (self::CONTACT_WORKED_COLUMNS as $c) {
                $w->orWhereNotNull('contacts.' . $c);
            }
            $w->orWhereExists(fn (Builder $s) => $s->selectRaw('1')
                ->from('seller_outreach_sends as so')
                ->whereColumn('so.contact_id', 'contacts.id'));
        });
    }

    /** Property counts if it shows any CoreX engagement (else it is an untouched historical import). */
    public static function properties(Builder $q): Builder
    {
        if (self::countingRaw()) {
            return $q;
        }

        return $q->where(function (Builder $w) {
            foreach (self::PROPERTY_WORKED_COLUMNS as $c) {
                $w->orWhereNotNull('properties.' . $c);
            }
            $w->orWhereIn('properties.status', self::PROPERTY_LIVE_STATUSES);
            $w->orWhereExists(fn (Builder $s) => $s->selectRaw('1')
                ->from('property_notes as pn')
                ->whereColumn('pn.property_id', 'properties.id'));
        });
    }

    /** FICA counts only when its contact is a genuine (native-or-worked) contact. */
    public static function ficaViaContact(Builder $q): Builder
    {
        if (self::countingRaw()) {
            return $q;
        }

        return $q->whereExists(function (Builder $s) {
            $s->selectRaw('1')->from('contacts as c')
                ->whereColumn('c.id', 'fica_submissions.contact_id')
                ->whereNull('c.deleted_at')
                ->where(function (Builder $w) {
                    $w->whereNull('c.loaded_at');
                    foreach (self::CONTACT_WORKED_COLUMNS as $col) {
                        $w->orWhereNotNull('c.' . $col);
                    }
                    $w->orWhereExists(fn (Builder $s2) => $s2->selectRaw('1')
                        ->from('seller_outreach_sends as so')
                        ->whereColumn('so.contact_id', 'c.id'));
                });
        });
    }
}
