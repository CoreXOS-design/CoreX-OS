<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Audit fix 2026-10-20 (finding 4) - take away again the grants that 2026_11_19_100000_grant_rental_notices_view_and_review_audit_a17_a28
 * RESURRECTED on a database where its first version already ran.
 *
 * That first version restored soft-deleted `rental_notices.view` / `rental_notices.review` rows - grants an administrator had deliberately
 * removed - for every role still holding `rental_notices.create` / `leases.renew`. The migration file is fixed (a fresh run never restores),
 * but it has already run on QA1 / Staging, so the resurrected rows are live there.
 *
 * HOW THEY ARE FOUND: nothing records "this grant was restored", but a restore stamps `updated_at` with the migration's own run time while
 * `created_at` stays at the day the row was first made. So: the ACTIVE `view` / `review` rows whose `updated_at` is more than an hour after
 * `created_at`, clustered by `updated_at` (rows within 90 seconds of each other are one cluster) - the LARGEST cluster is that one run. Those
 * rows are soft-deleted again (never a hard delete; an administrator restores any of them from the Role Manager in one click).
 *
 * WHY THIS IS SAFE TO RUN: it only ever REMOVES, never adds; it touches nothing on a database where the first version never ran (rows
 * created by a fresh run have created_at = updated_at, so there is no cluster); it is meant to run ONCE (the migrations table sees to that - a
 * forced second run would treat the next-largest cluster as the resurrected one, which is why down() is deliberately a no-op and the file is
 * never to be re-run by hand); and every row it removes is written to the log. The mistake it could make - revoking a grant an administrator re-ticked in
 * the same minute as the deploy - is the cautious direction and is one click to undo.
 */
return new class extends Migration
{
    private const KEYS = ['rental_notices.view', 'rental_notices.review'];

    public function up(): void
    {
        $candidates = RolePermission::whereIn('permission_key', self::KEYS)
            ->whereNotNull('created_at')->whereNotNull('updated_at')
            ->get()
            ->filter(fn (RolePermission $r) => $r->updated_at->timestamp - $r->created_at->timestamp > 3600)
            ->sortBy(fn (RolePermission $r) => $r->updated_at->timestamp)
            ->values();
        if ($candidates->isEmpty()) {
            return;
        }

        $clusters = [];
        $current = [];
        $last = null;
        foreach ($candidates as $row) {
            $t = $row->updated_at->timestamp;
            if ($last !== null && $t - $last > 90) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $row;
            $last = $t;
        }
        $clusters[] = $current;
        usort($clusters, fn ($a, $b) => count($b) <=> count($a));
        $resurrected = $clusters[0];

        $detail = [];
        foreach ($resurrected as $row) {
            $detail[] = $row->role . '/' . $row->permission_key . '/agency:' . ($row->agency_id ?? 'null');
            $row->delete(); // soft delete - the grant was deliberately revoked before the first version of the migration restored it
        }
        Log::warning('rental_notices grants restored by the A17/A28 migration were revoked again', ['count' => count($detail), 'detail' => $detail]);
    }

    public function down(): void
    {
        // Not reversed: re-granting would bring back the very rights an administrator had removed.
    }
};
