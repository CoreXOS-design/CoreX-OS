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

    /** deed columns carried to the survivor when it has none (paid-for data must not be lost by a merge) */
    private const DEED_COLUMNS = [
        'title_deed_number', 'deeds_office', 'deeds_registered_date', 'bond_holder', 'bond_amount', 'sale_type', 'deeds_captured_at', 'deeds_captured_by_user_id',
        'erf_number', 'erf_portion', 'lpi_code', 'township', 'scheme_name', 'scheme_number', 'section_number',
        'last_known_sold_price', 'last_known_sold_date', 'municipal_valuation', 'municipal_valuation_year',
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
        // two work records (claim / status / reason / own stock) are ONE record afterwards; with only one of them, the generic move below carries it
        $combined = $this->combineWorkRecords($winner, $loser, $apply, $step);

        foreach (self::FILE_TABLES as $table) {
            if ($table === 'tracked_property_agency_work' && $combined['both']) {
                continue;
            }
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
            } elseif ($winner->owner_contact_id && $loser->owner_contact_id && (int) $winner->owner_contact_id !== (int) $loser->owner_contact_id) {
                // two different contacts on the two files: the winner's stays linked, the other is kept in the history (never dropped)
                $this->workEvent($agencyId, (int) $winner->id, 'merge', null, null, 'Merged file #' . $loser->id . ' was linked to contact #' . $loser->owner_contact_id . '.');
            }
            // deed data the winner lacks is carried over (never overwritten): the paid-for deeds capture survives a merge
            foreach (self::DEED_COLUMNS as $col) {
                if (($winner->{$col} === null || $winner->{$col} === '') && $loser->{$col} !== null && $loser->{$col} !== '') {
                    $winnerUpdate[$col] = $loser->{$col};
                }
            }
            if ($winnerUpdate) {
                DB::table('tracked_properties')->where('id', $winner->id)->update($winnerUpdate);
                foreach ($winnerUpdate as $col => $new) {
                    $this->log($step, 'tracked_properties', (int) $winner->id, (string) $col, $winner->{$col}, $new);
                }
            }
            DB::table('tracked_properties')->where('id', $loser->id)->update([
                'status' => TrackedProperty::STATUS_DUPLICATE, 'duplicate_of_tracked_property_id' => $winner->id, 'updated_at' => now(),
            ]);
            $this->log($step, 'tracked_properties', (int) $loser->id, 'status', (string) $loser->status, TrackedProperty::STATUS_DUPLICATE);
        }

        return ['merged' => true, 'reason' => null, 'moved' => $moved, 'combined' => $combined['folded']];
    }

    /**
     * property-identity.md section 17 / mic-work-tab.md section 4 (AT-520 step 4.1): when BOTH files hold this agency's work record, fold the loser's into
     * the winner's. The claim is kept (the winner's when two agents claimed), the most recent status wins with its reason, own-stock is OR-ed, and
     * whatever loses is written to the history ("Merged file #N ...") - nothing is dropped. The history lines of the loser move with the generic move.
     * The loser's record is soft-deleted afterwards (never hard-deleted; it keeps its row for the journal).
     *
     * @return array{both:bool, folded:list<string>}
     */
    private function combineWorkRecords(TrackedProperty $winner, TrackedProperty $loser, bool $apply, string $step): array
    {
        $agencyId = (int) $winner->agency_id;
        $row = fn (int $tpId) => DB::table('tracked_property_agency_work')->where('agency_id', $agencyId)->where('tracked_property_id', $tpId)->whereNull('deleted_at')->first();
        $lw = $row((int) $loser->id);
        $ww = $row((int) $winner->id);
        if (! $lw || ! $ww) {
            return ['both' => false, 'folded' => []];
        }

        $set = [];
        $folded = [];
        $note = 'Merged file #' . $loser->id;
        if ($lw->claimed_by_user_id) {
            if (! $ww->claimed_by_user_id) {
                $set += ['claimed_by_user_id' => $lw->claimed_by_user_id, 'claimed_at' => $lw->claimed_at];
                $folded[] = 'claim';
            } elseif ((int) $ww->claimed_by_user_id !== (int) $lw->claimed_by_user_id) {
                $folded[] = 'claim_conflict';
                if ($apply) {
                    $this->workEvent($agencyId, (int) $winner->id, 'claim', null, null, $note . ' was claimed by user #' . $lw->claimed_by_user_id . ($lw->claimed_at ? ' on ' . substr((string) $lw->claimed_at, 0, 10) : '') . '; the claim by user #' . $ww->claimed_by_user_id . ' stays.', (int) $lw->claimed_by_user_id);
                }
            }
        }
        if ($lw->prospecting_status) {
            $take = false;
            if (! $ww->prospecting_status) {
                $take = true;
                $folded[] = 'status';
            } elseif ($ww->prospecting_status !== $lw->prospecting_status) {
                $folded[] = 'status_conflict';
                $take = $lw->status_set_at && (! $ww->status_set_at || $lw->status_set_at > $ww->status_set_at);
                if ($apply) {
                    $loserSays = "'" . $lw->prospecting_status . "'" . ($lw->prospecting_reason ? ' (' . $lw->prospecting_reason . ')' : '') . ($lw->status_set_at ? ', set ' . substr((string) $lw->status_set_at, 0, 10) : '');
                    $this->workEvent($agencyId, (int) $winner->id, 'status', $take ? $ww->prospecting_status : $lw->prospecting_status, $take ? $lw->prospecting_status : $ww->prospecting_status,
                        $take ? $note . ' had the newer status ' . $loserSays . '.' : $note . ' had the older status ' . $loserSays . '; the newer one stays.', $lw->status_set_by_user_id ? (int) $lw->status_set_by_user_id : null);
                }
            } elseif (! $ww->prospecting_reason && $lw->prospecting_reason) {
                $set['prospecting_reason'] = $lw->prospecting_reason;
                $folded[] = 'reason';
            }
            if ($take) {
                $set += ['prospecting_status' => $lw->prospecting_status, 'prospecting_reason' => $lw->prospecting_reason, 'status_set_by_user_id' => $lw->status_set_by_user_id, 'status_set_at' => $lw->status_set_at];
            }
        }
        if ($lw->is_own_stock && ! $ww->is_own_stock) {
            $set += ['is_own_stock' => 1, 'own_stock_property_id' => $lw->own_stock_property_id, 'own_stock_source' => $lw->own_stock_source, 'own_stock_synced_at' => $lw->own_stock_synced_at];
            $folded[] = 'own_stock';
        }

        if ($apply) {
            if ($set) {
                DB::table('tracked_property_agency_work')->where('id', $ww->id)->update($set + ['updated_at' => now()]);
                foreach ($set as $col => $new) {
                    $this->log($step, 'tracked_property_agency_work', (int) $ww->id, (string) $col, $ww->{$col}, $new);
                }
            }
            DB::table('tracked_property_agency_work')->where('id', $lw->id)->update(['deleted_at' => now()]);
            $this->log($step, 'tracked_property_agency_work', (int) $lw->id, 'deleted_at', null, 'soft-deleted (folded into #' . $ww->id . ')');
        }

        return ['both' => true, 'folded' => $folded];
    }

    private function workEvent(int $agencyId, int $trackedPropertyId, string $kind, ?string $from, ?string $to, string $reason, ?int $userId = null): void
    {
        DB::table('tracked_property_agency_work_events')->insert([
            'agency_id' => $agencyId, 'tracked_property_id' => $trackedPropertyId, 'kind' => $kind, 'from_value' => $from, 'to_value' => $to,
            'reason' => mb_substr($reason, 0, 500), 'user_id' => $userId, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
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
