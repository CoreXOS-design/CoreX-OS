<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-inspections.md §20.16.2 — one-time data migration from
 * the old pairwise `rental_inspection_photo_matches` into the new group
 * tables. Runs automatically on `migrate` (not a manual Artisan command a
 * deploy could forget) so QA1's already-matched test data carries over
 * without a separate step.
 *
 * Pairwise rows form a graph (photo <-> photo edges); every connected
 * component becomes exactly one group, with every photo in that component
 * as a member. This is deliberately connected-components, not "one group
 * per pairwise row" — if A was matched to B, and separately B to C, the
 * OLD system already couldn't see that A and C are related (matchFor()/
 * matchPartnerId() in show.blade.php only ever compared the two currently-
 * displayed photos directly, never walked the graph); collapsing the whole
 * component into one group is what makes that transitive relationship
 * visible for the first time, not a change to what was already there.
 *
 * Nothing is deleted here — this INSERTs into the new tables and leaves
 * `rental_inspection_photo_matches` exactly as it was, a historical record
 * the app no longer reads.
 *
 * Plain DB queries throughout, not Eloquent models — a data migration
 * should not depend on application model classes whose shape can change
 * out from under it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $edges = DB::table('rental_inspection_photo_matches')
            ->whereNull('deleted_at')
            ->orderBy('matched_at')
            ->get(['id', 'agency_id', 'property_id', 'photo_id_a', 'photo_id_b', 'matched_by_user_id', 'matched_at']);

        if ($edges->isEmpty()) {
            return;
        }

        // Union-find over photo ids touched by any active edge.
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            if (! isset($parent[$x])) {
                $parent[$x] = $x;
            }
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }

            return $parent[$x];
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $rootA = $find($a);
            $rootB = $find($b);
            if ($rootA !== $rootB) {
                $parent[$rootB] = $rootA;
            }
        };

        foreach ($edges as $edge) {
            $union((int) $edge->photo_id_a, (int) $edge->photo_id_b);
        }

        // Group every touched photo by its component root, and separately
        // track: per component, its earliest edge (for the group's own
        // created_by/created_at); per photo, the earliest edge that
        // touched IT specifically (for that member's added_by/added_at).
        $componentPhotos = []; // root => [photo_id, ...]
        $componentFirstEdge = []; // root => edge (earliest matched_at touching this component)
        $photoFirstEdge = []; // photo_id => edge (earliest matched_at touching this photo)
        $photoRootProperty = []; // root => ['agency_id' => ..., 'property_id' => ...]

        foreach ($edges as $edge) {
            $root = $find((int) $edge->photo_id_a);
            foreach ([(int) $edge->photo_id_a, (int) $edge->photo_id_b] as $photoId) {
                $componentPhotos[$root][$photoId] = true;
                if (! isset($photoFirstEdge[$photoId])) {
                    $photoFirstEdge[$photoId] = $edge;
                }
            }
            if (! isset($componentFirstEdge[$root])) {
                $componentFirstEdge[$root] = $edge;
                $photoRootProperty[$root] = ['agency_id' => $edge->agency_id, 'property_id' => $edge->property_id];
            }
        }

        $now = now();
        foreach ($componentPhotos as $root => $photoIds) {
            $firstEdge = $componentFirstEdge[$root];
            $groupId = DB::table('rental_inspection_photo_match_groups')->insertGetId([
                'agency_id' => $photoRootProperty[$root]['agency_id'],
                'property_id' => $photoRootProperty[$root]['property_id'],
                'created_by_user_id' => $firstEdge->matched_by_user_id,
                'created_at' => $firstEdge->matched_at,
                'updated_at' => $now,
            ]);

            $memberRows = [];
            foreach (array_keys($photoIds) as $photoId) {
                $photoEdge = $photoFirstEdge[$photoId];
                $memberRows[] = [
                    'agency_id' => $photoRootProperty[$root]['agency_id'],
                    'rental_inspection_photo_match_group_id' => $groupId,
                    'rental_inspection_photo_id' => $photoId,
                    'added_by_user_id' => $photoEdge->matched_by_user_id,
                    'added_at' => $photoEdge->matched_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('rental_inspection_photo_match_group_members')->insert($memberRows);
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: down() would need to reconstruct the
        // exact original pairwise rows from a group shape that has already
        // discarded which specific pairs were once directly compared —
        // not a safe or meaningful inverse. The original table (untouched
        // by up()) remains the historical record either way.
        DB::table('rental_inspection_photo_match_group_members')->delete();
        DB::table('rental_inspection_photo_match_groups')->delete();
    }
};
