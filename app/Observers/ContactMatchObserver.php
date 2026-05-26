<?php

namespace App\Observers;

use App\Models\ContactMatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        if (Auth::check() && $m->updated_by_user_id === null) {
            $m->updated_by_user_id = Auth::id();
        }
    }

    public function updating(ContactMatch $m): void
    {
        if (Auth::check() && $m->isDirty() && !$m->isDirty('updated_by_user_id')) {
            $m->updated_by_user_id = Auth::id();
        }
    }

    public function saved(ContactMatch $m): void
    {
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
}
