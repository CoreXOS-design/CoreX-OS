<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedProperty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Property identity apply (.ai/specs/property-identity.md §16): the only code that moves things between two property
 * files of one agency.
 *
 *   - moveAdvert(): one portal advert (and everything that hangs on that advert) moves from one file to another.
 *   - merge():      the loser file is folded into the winner — every row that points at the loser points at the winner,
 *                   and the loser is flagged `duplicate` with `duplicate_of_tracked_property_id` (never deleted).
 *
 * Moves use UPDATE IGNORE on the tables that carry a unique key (holds, flags, prospects, agency work), so a row that
 * would clash stays on the loser, still reachable through duplicate_of. Nothing is hard-deleted (non-negotiable #1).
 * Every moved row is written to the journal (table, id, old file) so the step can be undone from it.
 */
final class TrackedPropertyMerger
{
    /** every table that points at a property file with `tracked_property_id` (verified against QA1 information_schema) */
    private const FILE_TABLES = [
        'deals', 'holding_cost_data_points', 'market_data_points', 'market_report_comp_rows', 'p24_listings', 'portal_listings',
        'pp_alert_listings', 'presentation_sold_comps', 'presentations', 'properties', 'property_take_requests', 'prospecting_listings',
        'prospecting_pitch_locks', 'rts_contact_outcomes', 'rts_holds', 'rts_list_items', 'rts_property_flags', 'rts_prospects',
        'tracked_property_addresses', 'tracked_property_agency_work', 'tracked_property_agency_work_events', 'tracked_property_comments',
        'tracked_property_external_refs', 'tracked_property_owners', 'tva_contact_captures',
    ];

    /** the tables an advert's rows live in: table => has an agency column */
    private const ADVERT_TABLES = ['tracked_property_external_refs', 'prospecting_listings', 'portal_listings', 'p24_listings', 'pp_alert_listings'];

    /** @var resource|null */
    private $journal = null;

    public function journalTo(?string $path): void
    {
        if ($this->journal) {
            fclose($this->journal);
            $this->journal = null;
        }
        if ($path) {
            @mkdir(dirname($path), 0775, true);
            $this->journal = fopen($path, 'ab');
        }
    }

    /**
     * Move one advert's rows from $from to $to (same agency). Returns rows moved per table.
     *
     * @return array<string,int>
     */
    public function moveAdvert(int $agencyId, string $canonicalRef, int $from, int $to, bool $apply, string $step = 'move_advert'): array
    {
        $moved = [];
        foreach (self::ADVERT_TABLES as $table) {
            if (! Schema::hasColumn($table, 'canonical_ref')) {
                continue;
            }
            $q = DB::table($table)->where('tracked_property_id', $from)->where('canonical_ref', $canonicalRef);
            if (Schema::hasColumn($table, 'agency_id')) {
                $q->where('agency_id', $agencyId);
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            $ids = (clone $q)->pluck('id')->all();
            if (! $ids) {
                continue;
            }
            $moved[$table] = count($ids);
            if ($apply) {
                DB::table($table)->whereIn('id', $ids)->update(['tracked_property_id' => $to]);
                foreach ($ids as $id) {
                    $this->log($step, $table, (int) $id, 'tracked_property_id', $from, $to);
                }
            }
        }

        return $moved;
    }

    /** Which of two files should survive a merge: promoted > deeds-captured > has a street > more adverts > older. */
    public function chooseWinner(TrackedProperty $a, TrackedProperty $b): array
    {
        $score = function (TrackedProperty $t): array {
            $adverts = DB::table('tracked_property_external_refs')->where('tracked_property_id', $t->id)->whereNull('deleted_at')->count();

            return [
                $t->promoted_to_property_id ? 1 : 0,
                $t->capture_kind === 'deeds_capture' ? 1 : 0,
                trim((string) $t->street_name) !== '' ? 1 : 0,
                $adverts,
                -((int) $t->id),
            ];
        };

        return $score($a) >= $score($b) ? [$a, $b] : [$b, $a];
    }

    /**
     * Fold $loser into $winner. Returns ['merged' => bool, 'reason' => ?string, 'moved' => table => rows].
     *
     * @return array{merged:bool, reason:?string, moved:array<string,int>}
     */
    public function merge(TrackedProperty $winner, TrackedProperty $loser, bool $apply, string $step = 'merge', ?int $byUserId = null): array
    {
        if ((int) $winner->id === (int) $loser->id) {
            return ['merged' => false, 'reason' => 'same file', 'moved' => []];
        }
        if ((int) $winner->agency_id !== (int) $loser->agency_id) {
            return ['merged' => false, 'reason' => 'different agencies', 'moved' => []];
        }
        if ($winner->promoted_to_property_id && $loser->promoted_to_property_id && (int) $winner->promoted_to_property_id !== (int) $loser->promoted_to_property_id) {
            return ['merged' => false, 'reason' => 'both files are promoted to different properties', 'moved' => []];
        }
        $erfA = trim((string) $winner->erf_number);
        $erfB = trim((string) $loser->erf_number);
        if ($erfA !== '' && $erfB !== '' && ltrim($erfA, '0') !== ltrim($erfB, '0')) {
            return ['merged' => false, 'reason' => 'different erf numbers', 'moved' => []];
        }

        $agencyId = (int) $winner->agency_id;
        $moved = [];
        $primaryOnWinner = DB::table('tracked_property_addresses')->where('tracked_property_id', $winner->id)->where('is_primary', 1)->whereNull('deleted_at')->exists();

        foreach (self::FILE_TABLES as $table) {
            $q = DB::table($table)->where('tracked_property_id', $loser->id);
            if (Schema::hasColumn($table, 'agency_id')) {
                $q->where('agency_id', $agencyId);
            }
            $ids = $q->pluck('id')->all();
            if (! $ids) {
                continue;
            }
            $moved[$table] = count($ids);
            if (! $apply) {
                continue;
            }
            if ($table === 'tracked_property_addresses' && $primaryOnWinner) {
                DB::table($table)->whereIn('id', $ids)->update(['is_primary' => 0]);
            }
            DB::statement(
                'UPDATE IGNORE `' . $table . '` SET `tracked_property_id` = ? WHERE `id` IN (' . implode(',', array_map('intval', $ids)) . ')',
                [$winner->id]
            );
            $actually = DB::table($table)->whereIn('id', $ids)->where('tracked_property_id', $winner->id)->pluck('id')->all();
            $moved[$table] = count($actually);
            foreach ($actually as $id) {
                $this->log($step, $table, (int) $id, 'tracked_property_id', (int) $loser->id, (int) $winner->id);
            }
        }

        if ($apply) {
            // the two columns that point at a file without being `tracked_property_id`
            DB::table('prospecting_listings')->where('linked_deed_tracked_property_id', $loser->id)->where('agency_id', $agencyId)
                ->update(['linked_deed_tracked_property_id' => $winner->id]);

            $winnerUpdate = [];
            if (! $winner->promoted_to_property_id && $loser->promoted_to_property_id) {
                $winnerUpdate += ['promoted_to_property_id' => $loser->promoted_to_property_id, 'promoted_at' => $loser->promoted_at, 'promoted_by_user_id' => $loser->promoted_by_user_id, 'status' => TrackedProperty::STATUS_PROMOTED];
            }
            if (! $winner->owner_contact_id && $loser->owner_contact_id) {
                $winnerUpdate['owner_contact_id'] = $loser->owner_contact_id;
            }
            if ($winnerUpdate) {
                DB::table('tracked_properties')->where('id', $winner->id)->update($winnerUpdate);
            }
            DB::table('tracked_properties')->where('id', $loser->id)->update([
                'status' => TrackedProperty::STATUS_DUPLICATE, 'duplicate_of_tracked_property_id' => $winner->id, 'updated_at' => now(),
            ]);
            $this->log($step, 'tracked_properties', (int) $loser->id, 'status', (string) $loser->status, TrackedProperty::STATUS_DUPLICATE);
        }

        return ['merged' => true, 'reason' => null, 'moved' => $moved];
    }

    /**
     * Is this file now an empty shell — nothing left on it but its own row? (no adverts, no owner, no work, no deeds, no
     * promotion). Only such a file may be flagged duplicate after its adverts moved away.
     */
    public function isEmptyShell(int $fileId): bool
    {
        $tp = DB::table('tracked_properties')->where('id', $fileId)->first(['promoted_to_property_id', 'capture_kind', 'owner_contact_id', 'status']);
        if ($tp === null || $tp->promoted_to_property_id || $tp->capture_kind || $tp->owner_contact_id || $tp->status !== TrackedProperty::STATUS_ACTIVE) {
            return false;
        }
        foreach (self::FILE_TABLES as $table) {
            if ($table === 'tracked_property_addresses') {
                continue;
            }
            if (DB::table($table)->where('tracked_property_id', $fileId)->exists()) {
                return false;
            }
        }

        return true;
    }

    public function flagDuplicate(int $fileId, int $ofId, string $step): void
    {
        DB::table('tracked_properties')->where('id', $fileId)->update([
            'status' => TrackedProperty::STATUS_DUPLICATE, 'duplicate_of_tracked_property_id' => $ofId, 'updated_at' => now(),
        ]);
        $this->log($step, 'tracked_properties', $fileId, 'status', TrackedProperty::STATUS_ACTIVE, TrackedProperty::STATUS_DUPLICATE);
    }

    public function log(string $step, string $table, int $id, string $column, mixed $old, mixed $new): void
    {
        if ($this->journal) {
            fwrite($this->journal, json_encode(['step' => $step, 'table' => $table, 'id' => $id, 'column' => $column, 'old' => $old, 'new' => $new], JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
}
