<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * QA2 audit follow-up to AT-424 (2026_09_21_000001_add_agency_id_to_rentals_table).
 *
 * Before that fix, RentalsController::store/update synced `rental_agents` with no
 * agency check at all, so a pre-fix rental could already have another agency's
 * agent attached. That migration backfilled `rentals.agency_id` correctly but did
 * nothing about the pivot table — any bad link created before 2026-09-21 survives
 * untouched and still feeds WorksheetController::index() and
 * RentalWorksheetInclusionService today.
 *
 * This scrubs exactly that: a `rental_agents` row whose linked user's agency_id
 * disagrees with its rental's (now-correct) agency_id. Not exploitable going
 * forward — RentalsController::sameAgencyAgentIds() already filters posted agent
 * ids to the rental's own agency before sync() — this is pre-fix residue only.
 *
 * `rental_agents` is a pivot/relationship table, not a primary record with its
 * own identity (non-negotiable #1 governs Documents/Deals/Contacts/Templates/
 * Users, not join rows) — Rental::agents()->sync() already performs a genuine
 * delete+insert on this exact table as normal, routine business logic every time
 * a rental's agent list is saved. Removing a stale cross-agency link the same
 * way is consistent with that, not a departure from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_agents') || ! Schema::hasColumn('rentals', 'agency_id')) {
            return;
        }

        $staleIds = DB::table('rental_agents as ra')
            ->join('rentals as r', 'r.id', '=', 'ra.rental_id')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereNotNull('u.agency_id')
            ->whereColumn('u.agency_id', '<>', 'r.agency_id')
            ->pluck('ra.id');

        if ($staleIds->isEmpty()) {
            return;
        }

        DB::table('rental_agents')->whereIn('id', $staleIds)->delete();

        Log::channel('security')->warning('QA2 audit: scrubbed cross-agency rental_agents rows pre-dating AT-424', [
            'rental_agent_ids' => $staleIds->all(),
            'count'            => $staleIds->count(),
        ]);
    }

    public function down(): void
    {
        // Intentionally a no-op — there is no "un-scrub" that would not
        // resurrect exactly the cross-agency links this migration removes.
    }
};
