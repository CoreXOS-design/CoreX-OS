<?php

namespace App\Observers;

use App\Jobs\RegenerateBuyerMatchesJob;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Observer for ContactMatch.
 *
 * Responsibilities:
 *  - Stamp updated_by_user_id on creating/updating (per spec D9).
 *  - Default is_primary=true when a contact gets its first wishlist (D1).
 *  - Enforce single-primary uniqueness per contact via demotion of siblings.
 *  - On soft-delete of the primary, promote the next-most-recently-updated
 *    sibling to take its place.
 *
 * Recursion-prevention strategy:
 *  - The static $demoting flag short-circuits saved() so callers that
 *    have already demoted siblings (e.g. ContactMatch::setAsPrimary())
 *    do not trigger a second demotion when they save the promoted row.
 *  - Sibling demotion uses a direct DB query builder update() so it
 *    bypasses model events entirely on the demoted rows.
 */
class ContactMatchObserver
{
    /** Re-entry guard. Set by the observer itself and by setAsPrimary(). */
    public static bool $demoting = false;

    public function creating(ContactMatch $m): void
    {
        // First match for this contact → primary by default (D1).
        $siblings = ContactMatch::where('contact_id', $m->contact_id)->count();
        if ($siblings === 0 && $m->is_primary === null) {
            $m->is_primary = true;
        } elseif ($siblings === 0 && !$m->is_primary) {
            // Still nothing else exists — let the first match win primary.
            $m->is_primary = true;
        }

        // Only stamp when the authenticated principal is a staff User — the
        // column FKs to `users`. Client-portal requests authenticate as a
        // ClientUser (separate table), so Auth::id() there is NOT a users.id;
        // stamping it triggered a FK violation on insert. Leave it null for
        // client-driven changes (the FK is nullable / ON DELETE SET NULL).
        if ($m->updated_by_user_id === null && Auth::user() instanceof User) {
            $m->updated_by_user_id = Auth::id();
        }
    }

    public function updating(ContactMatch $m): void
    {
        if (Auth::user() instanceof User && $m->isDirty() && !$m->isDirty('updated_by_user_id')) {
            $m->updated_by_user_id = Auth::id();
        }
    }

    public function saved(ContactMatch $m): void
    {
        // AT-71 freshness — a wishlist create/update can change its countability
        // and its scores, so refresh this contact's cached match rows promptly
        // (queued, per-contact scoped). The 04:00/04:30 crons remain the
        // full fallback sweep. Skipped during internal sibling-demotion re-entry.
        if (!self::$demoting) {
            $this->dispatchRecompute($m);
        }

        if (self::$demoting) {
            return;
        }
        if (!$m->wasChanged('is_primary')) {
            return;
        }
        if ($m->is_primary !== true) {
            return;
        }

        self::$demoting = true;
        try {
            DB::transaction(function () use ($m) {
                ContactMatch::where('contact_id', $m->contact_id)
                    ->where('id', '!=', $m->id)
                    ->whereNull('deleted_at')
                    ->update(['is_primary' => false]);
            });
        } finally {
            self::$demoting = false;
        }
    }

    public function deleted(ContactMatch $m): void
    {
        // AT-71 freshness — removing a wishlist changes the contact's match set;
        // clear+rebuild this contact's cached rows (queued, scoped).
        $this->dispatchRecompute($m);

        // Eloquent fires `deleted` on soft-delete; the row now has deleted_at set.
        if (!$m->is_primary) {
            return;
        }

        $next = ContactMatch::where('contact_id', $m->contact_id)
            ->where('id', '!=', $m->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id') // deterministic tiebreaker
            ->first();

        if ($next) {
            $next->is_primary = true;
            $next->save();
        }
    }

    /**
     * AT-71 — queue a scoped recompute of the cached match tables for this
     * wishlist's contact. Idempotent + per-contact scoped (truncate+rebuild).
     * A dispatch failure must never break the wishlist save (BUILD_STANDARD §4).
     */
    private function dispatchRecompute(ContactMatch $m): void
    {
        if (!$m->contact_id) {
            return;
        }
        try {
            RegenerateBuyerMatchesJob::dispatch(
                agencyId: $m->agency_id ? (int) $m->agency_id : null,
                contactId: (int) $m->contact_id,
                truncate: true,
            );
        } catch (\Throwable $e) {
            Log::warning('ContactMatchObserver: recompute dispatch failed', [
                'contact_id' => $m->contact_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
