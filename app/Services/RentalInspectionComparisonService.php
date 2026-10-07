<?php

namespace App\Services;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\PropertyRoom;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionSetting;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-inspection-form.md §7 — the in-vs-out comparison the
 * paper form's own printed instructions say is the entire point of doing
 * an in-inspection at all: "...uses the move-in checklist during the
 * pre-move out inspection and again when determaning if any of the
 * tenant's deposit will be retained..."
 *
 * Produces PROPOSED findings only — item + both conditions + both sets of
 * notes/photos + a classification an agent reviews. Nothing here computes
 * an amount, a currency value, or a deduction. That is explicitly Johan's
 * ruling to make (§7.2/§10 of the spec) and is not built by this class.
 *
 * Every method is read-only over existing data except recordFinding(),
 * which persists only a human judgement (wear-and-tear vs flagged),
 * never a derived fact — see RentalInspectionItemFinding's own docblock.
 */
class RentalInspectionComparisonService
{
    /**
     * cc2 (31ec28ae1, N/A + agency-configurable condition vocabulary —
     * "was never here, not an argument at all") shipped N/A as a FIXED key,
     * `'n_a'`, regardless of an agency's own condition vocabulary —
     * RentalInspectionRecordingController::markRoomNa() writes this exact
     * literal, never an agency-resolved key, and validates against it the
     * same way. The vocabulary itself (which OTHER states exist, their
     * labels, whether they require notes) is agency-configurable per
     * RentalInspectionSetting::conditionStatesFor() — but N/A's identity as
     * "not a real grade" is not something this class needs to re-derive
     * from that vocabulary; it matches the one fixed sentinel the rest of
     * the system already uses, the same way markRoomNa() does.
     *
     * Referenced as the literal string, not RentalInspectionObservation::
     * CONDITION_NA — that constant does not exist yet on this checkout
     * (cc2's commit hasn't landed on QA1 at the time this was written).
     * Once it lands, this is a one-line swap to the constant; the stored
     * value is identical either way.
     */
    private const CONDITION_NA = 'n_a';

    public const ONLY_AT_IN = 'only_at_in';
    public const ONLY_AT_OUT = 'only_at_out';
    public const NA_BOTH = 'na_both';
    public const NA_MISMATCH = 'na_mismatch';
    public const UNCHANGED = 'unchanged';
    public const IMPROVED = 'improved';
    public const DECLINED = 'declined';

    /**
     * Classifications an agent may record a judgement against — every row that DIFFERS from the move-in (§45.7a item 4:
     * "a finding can now be recorded on any marked row", not only a declined one).
     */
    public const REVIEWABLE_CLASSIFICATIONS = [self::DECLINED, self::NA_MISMATCH, self::ONLY_AT_IN, self::ONLY_AT_OUT];

    // §45.7a — the read-time difference keys the Move-out comparison screen marks rows with. Derived, never stored.
    public const DIFF_WORSE = 'worse';
    public const DIFF_DIFFERENT = 'different';
    public const DIFF_SAME = 'same';
    public const DIFF_BETTER = 'better';
    public const DIFF_NEW_ITEM = 'new_item';
    public const DIFF_NOT_AT_OUT = 'not_at_move_out';

    /** Rows an agent should look at: the outgoing state is not simply the move-in state. */
    public const MARKED_DIFFERENCES = [self::DIFF_WORSE, self::DIFF_DIFFERENT, self::DIFF_NEW_ITEM, self::DIFF_NOT_AT_OUT];

    public const DIFFERENCE_LABELS = [
        self::DIFF_WORSE => 'Worse than move-in',
        self::DIFF_DIFFERENT => 'Different from move-in',
        self::DIFF_SAME => 'Same as move-in',
        self::DIFF_BETTER => 'Better',
        self::DIFF_NEW_ITEM => 'New item',
        self::DIFF_NOT_AT_OUT => 'Not at move-out',
    ];

    /** @var array<int|string, array<string, array{label: string, severity: string}>> agency id => condition key => label/severity, read once per agency per service instance */
    private array $vocabulary = [];

    /** One read of the agency's condition vocabulary per service instance — never a query per row (§45.7a, a screen of hundreds of rows). */
    private function stateFor(?int $agencyId, string $conditionKey): ?array
    {
        $cacheKey = $agencyId ?? 0;
        if (! isset($this->vocabulary[$cacheKey])) {
            $this->vocabulary[$cacheKey] = collect(RentalInspectionSetting::conditionStatesFor($agencyId))
                ->mapWithKeys(fn ($s) => [$s['key'] => [
                    'label' => $s['label'],
                    // Same default RentalInspectionSetting::conditionSeverityFor() uses: an unrecognised severity reads as red.
                    'severity' => in_array($s['severity'] ?? 'red', array_keys(RentalInspectionSetting::SEVERITY_COLORS), true) ? ($s['severity'] ?? 'red') : 'red',
                ]])->all();
        }

        return $this->vocabulary[$cacheKey][$conditionKey] ?? null;
    }

    /** The agency's severity bucket for a condition key; an unknown key is red (the cautious default shared with the colour logic). */
    private function severityFor(?int $agencyId, string $conditionKey): string
    {
        return $this->stateFor($agencyId, $conditionKey)['severity'] ?? 'red';
    }

    /** Severity bucket -> rank (green < amber < red). A neutral custom state sits with the calm ones. */
    private const SEVERITY_RANK = ['blue' => 0, 'grey' => 0, 'amber' => 1, 'red' => 2];

    /**
     * The matching lease's in-inspection for this out-inspection, if one
     * exists. Two real corrections, both found by verifying directly
     * against real QA1 data (lease 5, property 1724), not assumed:
     *
     * 1. A naive "latest by id" is wrong — a real lease can have MULTIPLE
     *    in-inspection rows (an agent restarted it, leaving abandoned
     *    drafts with zero observations alongside the one actually
     *    completed with real evidence), and the highest id is not reliably
     *    the completed one. Prefers the most recent COMPLETED
     *    in-inspection; only falls back to the most recent of any status if
     *    none was ever completed (e.g. the out-inspection is being done
     *    before the in-inspection was formally closed out — still worth
     *    comparing against whatever was recorded, per Johan's own "both are
     *    recorded either way" ruling, rental-inspections.md §0.1).
     * 2. Must search archived (soft-deleted) in-inspections too — this
     *    lease's real in-inspection (id 2) was archived on QA1 the same day
     *    as its out-inspection, and excluding it silently produced
     *    "only_at_out" for every item instead of the real comparison.
     *    Archiving hides an inspection from the working list; it must never
     *    hide its evidence from a comparison that depends on it — the same
     *    "retiring only blocks new writes, never hides history" principle
     *    rental_inspection_items already applies to itself (§3.3 of the
     *    existing spec).
     */
    public function matchingInInspection(RentalInspection $outInspection): ?RentalInspection
    {
        // §45.7a (Build I-7) — "outgoing vs incoming": the baseline is the tenancy's completed In, found along the
        // previous_lease_id chain (nearest lease first), so a renewed tenancy still compares against the move-in done on
        // an earlier term. Interim inspections and fault reports are context, never the baseline.
        $leaseIds = $this->tenancyLeaseIds($outInspection);

        $completed = RentalInspection::withTrashed()
            ->whereIn('lease_id', $leaseIds)
            ->where('type', RentalInspection::TYPE_IN)
            ->where('status', RentalInspection::STATUS_COMPLETED)
            ->get();
        if ($completed->isNotEmpty()) {
            foreach ($leaseIds as $leaseId) {
                $hit = $completed->where('lease_id', $leaseId)->sortByDesc('id')->first();
                if ($hit) {
                    return $hit;
                }
            }
        }

        return RentalInspection::withTrashed()
            ->whereIn('lease_id', $leaseIds)
            ->where('type', RentalInspection::TYPE_IN)
            ->get()
            ->sortByDesc(fn (RentalInspection $i) => [-array_search($i->lease_id, $leaseIds, true), $i->id])
            ->first();
    }

    /**
     * §45.7a — this out-inspection's lease first, then each previous_lease_id behind it (nearest first). Depth- and
     * cycle-guarded, the same walk RentalInspectionDueService uses.
     *
     * @return array<int, int>
     */
    public function tenancyLeaseIds(RentalInspection $outInspection): array
    {
        $chain = [(int) $outInspection->lease_id];
        $cursor = (int) $outInspection->lease_id;
        while (count($chain) < 25) {
            $previous = \App\Models\Lease::withoutGlobalScopes()->withTrashed()->where('id', $cursor)->value('previous_lease_id');
            if (! $previous || in_array((int) $previous, $chain, true)) {
                break;
            }
            $cursor = (int) $previous;
            $chain[] = $cursor;
        }

        return $chain;
    }

    /**
     * The full item-by-item comparison for one out-inspection against its
     * lease's in-inspection. Returns one entry per item that has AT LEAST
     * ONE graded (or otherwise present) observation on either side — an
     * item with observations on neither side never existed as far as this
     * tenancy is concerned and is not returned.
     *
     * Item matching is by rental_inspection_item_id — items are
     * property-scoped and reused across every inspection on that property
     * (RentalInspectionItem's own docblock), so the SAME room/space is the
     * SAME row on both the in- and out-inspection as long as the agent
     * picked it from the existing list rather than creating a new one.
     * There is no fuzzy label matching here, deliberately: if two agents
     * really did create two different item rows for what is physically the
     * same room (e.g. "Bathroom 1" vs the paper form's hand-labelled
     * "Ensuite Second Bedroom"), that is the SAME duplicate-item risk
     * already named and left unsolved in rental-inspections.md §7.2 — it
     * surfaces here as one row in only_at_in and a separate row in
     * only_at_out, which is the honest signal that something needs a
     * human's eyes, not a silent guess at which two rows are "really" the
     * same room.
     *
     * @return Collection<int, array{
     *   item: RentalInspectionItem,
     *   classification: string,
     *   in_observation: ?RentalInspectionObservation,
     *   out_observation: ?RentalInspectionObservation,
     *   finding: ?RentalInspectionItemFinding,
     * }>
     */
    public function compareItems(RentalInspection $outInspection): Collection
    {
        $inInspection = $this->matchingInInspection($outInspection);

        // AT-433, 2026-09-26 — ->recorded() excludes photo-anchor rows
        // (RentalInspectionObservation::CONDITION_PENDING) from both sides:
        // an item with only an unrecorded photo has nothing to compare yet,
        // and must never surface here as an empty-string "condition" that
        // classify() would treat as a real, gradeable finding.
        $inObservations = $inInspection
            ? RentalInspectionObservation::recorded()
                ->where('rental_inspection_id', $inInspection->id)
                ->orderBy('rental_inspection_item_id')
                ->latest('created_at')
                ->get()
                ->unique('rental_inspection_item_id')
                ->keyBy('rental_inspection_item_id')
            : collect();

        $outObservations = RentalInspectionObservation::recorded()
            ->where('rental_inspection_id', $outInspection->id)
            ->orderBy('rental_inspection_item_id')
            ->latest('created_at')
            ->get()
            ->unique('rental_inspection_item_id')
            ->keyBy('rental_inspection_item_id');

        $itemIds = $inObservations->keys()->merge($outObservations->keys())->unique();
        if ($itemIds->isEmpty()) {
            return collect();
        }

        $items = RentalInspectionItem::whereIn('id', $itemIds)->with('room')->get()->keyBy('id');

        $findings = RentalInspectionItemFinding::where('rental_inspection_id', $outInspection->id)
            ->whereNull('superseded_at')
            ->whereIn('rental_inspection_item_id', $itemIds)
            ->get()
            ->keyBy('rental_inspection_item_id');

        return $itemIds->map(function ($itemId) use ($items, $inObservations, $outObservations, $findings, $outInspection) {
            $item = $items->get($itemId);
            $inObs = $inObservations->get($itemId);
            $outObs = $outObservations->get($itemId);

            return [
                'item' => $item,
                'classification' => $this->classify($inObs, $outObs, $outInspection->agency_id),
                'in_observation' => $inObs,
                'out_observation' => $outObs,
                'finding' => $findings->get($itemId),
            ];
        })
            // §7.1 — N/A on both sides is not a finding at all, not even a
            // displayed-but-excluded row. Filtered out entirely, matching
            // Johan's own wording: "an item N/A at both ends is not a
            // finding."
            ->reject(fn (array $row) => $row['classification'] === self::NA_BOTH)
            ->values();
    }

    private function classify(?RentalInspectionObservation $in, ?RentalInspectionObservation $out, ?int $agencyId = null): string
    {
        if (! $in && ! $out) {
            // Cannot happen given compareItems()'s own item-id union, kept
            // as an explicit, honest branch rather than falling through.
            return self::NA_BOTH;
        }
        if (! $in) {
            return self::ONLY_AT_OUT;
        }
        if (! $out) {
            return self::ONLY_AT_IN;
        }

        $inGradeable = $in->condition !== self::CONDITION_NA;
        $outGradeable = $out->condition !== self::CONDITION_NA;

        if (! $inGradeable && ! $outGradeable) {
            return self::NA_BOTH;
        }
        if ($inGradeable !== $outGradeable) {
            return self::NA_MISMATCH;
        }

        // §45.7a — the old test hard-coded the literal 'good' ("improved" = moved TO good), though the baseline state is
        // agency-configurable (RentalInspectionSetting::baselineConditionKeyFor, an agency may have no 'good' at all). It now
        // reads the agency's own severity buckets. The three legacy labels map onto the new read-time difference:
        // worse AND different both stay DECLINED ("surfaced for the agent, never silently dropped"), better = IMPROVED.
        return match ($this->differenceFor($in, $out, $agencyId)['key']) {
            self::DIFF_SAME => self::UNCHANGED,
            self::DIFF_BETTER => self::IMPROVED,
            default => self::DECLINED,
        };
    }

    /**
     * §45.7a item 2 — the read-time difference between the move-in and the move-out state of ONE item, derived from the
     * agency's own configured severity buckets (RentalInspectionSetting::conditionSeverityFor(): green/blue < amber < red),
     * never from a stored flag and never from the literal 'good'.
     *
     *  worse     — the outgoing bucket is higher than the move-in bucket
     *  better    — lower
     *  different — same bucket, a different state (never auto-called worse: damaged -> missing is a change to look at,
     *              not a ranking the agency has ever expressed)
     *  same      — the same state on both sides
     *  not_at_move_out / new_item — recorded on one side only, or N/A on one side (it was here and is not, or the reverse)
     *  null key 'na_both' — N/A on both sides is not a row at all
     *
     * @return array{key: string, marked: bool}
     */
    public function differenceFor(?RentalInspectionObservation $in, ?RentalInspectionObservation $out, ?int $agencyId): array
    {
        $key = match (true) {
            ! $in && ! $out => 'na_both',
            ! $in => self::DIFF_NEW_ITEM,
            ! $out => self::DIFF_NOT_AT_OUT,
            $in->condition === self::CONDITION_NA && $out->condition === self::CONDITION_NA => 'na_both',
            $in->condition === self::CONDITION_NA => self::DIFF_NEW_ITEM,
            $out->condition === self::CONDITION_NA => self::DIFF_NOT_AT_OUT,
            default => $this->gradedDifference($in->condition, $out->condition, $agencyId),
        };

        return ['key' => $key, 'marked' => in_array($key, self::MARKED_DIFFERENCES, true)];
    }

    private function gradedDifference(string $inCondition, string $outCondition, ?int $agencyId): string
    {
        if ($inCondition === $outCondition) {
            return self::DIFF_SAME;
        }

        $rank = fn (string $c) => self::SEVERITY_RANK[$this->severityFor($agencyId, $c)] ?? 2;
        $inRank = $rank($inCondition);
        $outRank = $rank($outCondition);

        return $outRank > $inRank ? self::DIFF_WORSE : ($outRank < $inRank ? self::DIFF_BETTER : self::DIFF_DIFFERENT);
    }

    /**
     * §45.7a (Build I-7) — the Move-out comparison: ONE screen, both photo sets, differences marked at read time,
     * classified by the agent. The baseline is the tenancy's completed In (matchingInInspection(), chain-aware); every row
     * is derived — nothing here is stored except the agent's own judgement (RentalInspectionItemFinding).
     *
     * Rooms come back in the agency's walking order (PropertyRoom.sort_order, then id — the same order the property tab
     * uses), items in their own order within a room, items without a room last under "General". Photos are loaded once per
     * inspection (never per row) and paired by the existing photo match groups, paired ones first.
     *
     * $filters (all optional, all applied here so the screen and any export agree): q (item/room text), room (room id or
     * 'general'), difference (one of the DIFF_* keys), classification ('none' | 'any' | a finding disposition),
     * differences_only (bool), sort ('walking' default | 'severity').
     *
     * @param  array{q?: ?string, room?: ?string, difference?: ?string, classification?: ?string, differences_only?: bool, sort?: ?string}  $filters
     * @return array{
     *   baseline: ?RentalInspection, baseline_completed: bool, rooms: array<int, array<string, mixed>>,
     *   counts: array<string, int>, total_rows: int, shown_rows: int, header_facts: array, viewer: array<string, array<string, mixed>>
     * }
     */
    public function moveOutComparison(RentalInspection $out, array $filters = []): array
    {
        $agencyId = $out->agency_id;
        $in = $this->matchingInInspection($out);
        $leaseIds = $this->tenancyLeaseIds($out);

        $inObs = $in ? $this->latestRecordedByItem($in) : collect();
        $outObs = $this->latestRecordedByItem($out);

        $itemIds = $inObs->keys()->merge($outObs->keys())->unique()->values();
        $items = $itemIds->isEmpty()
            ? collect()
            : RentalInspectionItem::whereIn('id', $itemIds)->with('room')->get()->keyBy('id');

        $findings = RentalInspectionItemFinding::where('rental_inspection_id', $out->id)
            ->whereNull('superseded_at')->whereIn('rental_inspection_item_id', $itemIds)
            ->with('recordedBy')->get()->keyBy('rental_inspection_item_id');

        // Photos: two queries in total, never per row.
        $inPhotos = $in ? $this->photosByTarget($in) : ['items' => collect(), 'rooms' => collect()];
        $outPhotos = $this->photosByTarget($out);
        $allPhotoIds = collect([$inPhotos, $outPhotos])->flatMap(fn ($p) => $p['items']->flatten(1)->merge($p['rooms']->flatten(1)))->pluck('id');
        $groupOf = $allPhotoIds->isEmpty() ? collect() : \App\Models\RentalInspectionPhotoMatchGroupMember::whereIn('rental_inspection_photo_id', $allPhotoIds)
            ->pluck('rental_inspection_photo_match_group_id', 'rental_inspection_photo_id');

        // ── rows ──
        $rows = [];
        foreach ($itemIds as $itemId) {
            $item = $items->get($itemId);
            if (! $item) {
                continue;
            }
            $inO = $inObs->get($itemId);
            $outO = $outObs->get($itemId);
            $difference = $this->differenceFor($inO, $outO, $agencyId);
            if ($difference['key'] === 'na_both') {
                continue; // N/A on both sides is not a finding at all
            }

            $pairs = $this->pairPhotos($inPhotos['items']->get($itemId, collect()), $outPhotos['items']->get($itemId, collect()), $groupOf);
            $rows[] = [
                'key' => 'item-' . $itemId,
                'item' => $item,
                'room_id' => $item->property_room_id,
                'in' => $this->sideFor($inO, $agencyId),
                'out' => $this->sideFor($outO, $agencyId),
                'difference' => $difference['key'],
                'difference_label' => $this->differenceLabel($difference['key'], $inO, $outO, $agencyId),
                'marked' => $difference['marked'],
                'severity_rank' => $this->severityOrder($difference['key']),
                'finding' => $findings->get($itemId),
                'pairs' => $pairs,
                'context' => [],
            ];
        }

        // ── context timeline, marked rows only (one query per source, not per row) ──
        $markedItemIds = collect($rows)->where('marked', true)->pluck('item.id')->all();
        if ($markedItemIds !== []) {
            $context = $this->contextFor($markedItemIds, $leaseIds, [$in?->id, $out->id]);
            foreach ($rows as &$row) {
                if ($row['marked']) {
                    $row['context'] = $context[$row['item']->id] ?? [];
                }
            }
            unset($row);
        }

        $total = count($rows);
        $counts = array_fill_keys(array_keys(self::DIFFERENCE_LABELS), 0);
        foreach ($rows as $r) {
            $counts[$r['difference']]++;
        }

        $rows = array_values(array_filter($rows, fn (array $r) => $this->rowPasses($r, $filters)));

        // ── group into rooms, walking order ──
        $roomIds = collect($rows)->pluck('room_id')->merge($inPhotos['rooms']->keys())->merge($outPhotos['rooms']->keys())->filter()->unique();
        $rooms = $roomIds->isEmpty() ? collect() : PropertyRoom::whereIn('id', $roomIds)->get()->keyBy('id');

        $buckets = [];
        foreach ($rows as $r) {
            $buckets[$r['room_id'] ?: 0]['rows'][] = $r;
        }
        foreach ($roomIds as $rid) {
            if (! isset($buckets[$rid]) && $this->roomMatchesFilters($rooms->get($rid), $filters) && empty($filters['differences_only']) && ! $this->hasRowFilter($filters)) {
                $buckets[$rid]['rows'] = [];
            }
        }

        $roomList = [];
        foreach ($buckets as $rid => $bucket) {
            $room = $rid ? $rooms->get($rid) : null;
            $bucketRows = $bucket['rows'];
            usort($bucketRows, fn ($a, $b) => (($filters['sort'] ?? 'walking') === 'severity' ? $a['severity_rank'] <=> $b['severity_rank'] : 0)
                ?: (($a['item']->sort_order ?? 0) <=> ($b['item']->sort_order ?? 0)) ?: ($a['item']->id <=> $b['item']->id));

            $roomPairs = $rid ? $this->pairPhotos($inPhotos['rooms']->get($rid, collect()), $outPhotos['rooms']->get($rid, collect()), $groupOf) : [];
            $roomList[] = [
                'key' => 'room-' . ($rid ?: 'general'),
                'room_id' => $rid ?: null,
                'label' => $room?->label ?? 'General',
                'sort' => $room ? [(int) $room->sort_order, (int) $room->id] : [PHP_INT_MAX, PHP_INT_MAX],
                'rows' => $bucketRows,
                'room_pairs' => $roomPairs,
                'marked' => count(array_filter($bucketRows, fn ($r) => $r['marked'])),
                'worst' => $bucketRows === [] ? 99 : min(array_column($bucketRows, 'severity_rank')),
            ];
        }
        usort($roomList, fn ($a, $b) => (($filters['sort'] ?? 'walking') === 'severity' ? ($a['worst'] <=> $b['worst']) : 0) ?: ($a['sort'] <=> $b['sort']));

        // ── the paired-photo viewer's data, one entry per row/room that has any photo ──
        $viewer = [];
        foreach ($roomList as $room) {
            if ($room['room_pairs'] !== []) {
                $viewer[$room['key']] = ['label' => $room['label'] . ' — room photos', 'pairs' => $room['room_pairs']];
            }
            foreach ($room['rows'] as $r) {
                if ($r['pairs'] !== []) {
                    $viewer[$r['key']] = ['label' => $room['label'] . ' — ' . $r['item']->label, 'pairs' => $r['pairs']];
                }
            }
        }

        return [
            'baseline' => $in,
            'baseline_completed' => $in?->status === RentalInspection::STATUS_COMPLETED,
            'rooms' => $roomList,
            'counts' => $counts,
            'total_rows' => $total,
            'shown_rows' => array_sum(array_map(fn ($r) => count($r['rows']), $roomList)),
            'header_facts' => $this->compareHeaderFacts($in, $out),
            'viewer' => $viewer,
        ];
    }

    /** @return Collection<int, RentalInspectionObservation> the latest RECORDED observation per item (photo-anchor rows excluded). */
    private function latestRecordedByItem(RentalInspection $inspection): Collection
    {
        return RentalInspectionObservation::recorded()
            ->where('rental_inspection_id', $inspection->id)
            ->latest('created_at')->latest('id')
            ->get()
            ->unique('rental_inspection_item_id')
            ->keyBy('rental_inspection_item_id');
    }

    /** One side of a row: the agency's own LABEL for the condition (never the raw key), the note, and the severity bucket. */
    private function sideFor(?RentalInspectionObservation $obs, ?int $agencyId): ?array
    {
        if (! $obs) {
            return null;
        }
        $state = $this->stateFor($agencyId, $obs->condition);

        return [
            'condition' => $obs->condition,
            'label' => $state['label'] ?? ucfirst(str_replace('_', ' ', $obs->condition)),
            'severity' => $obs->condition === self::CONDITION_NA ? 'grey' : $this->severityFor($agencyId, $obs->condition),
            'note' => $obs->notes,
            'recorded_at' => $obs->created_at,
        ];
    }

    private function differenceLabel(string $key, ?RentalInspectionObservation $in, ?RentalInspectionObservation $out, ?int $agencyId): string
    {
        // An unchanged DEFECT is the one "same" row an agent must not mistake for something the tenant did.
        if ($key === self::DIFF_SAME && $out && in_array($this->severityFor($agencyId, $out->condition), ['red', 'amber'], true)) {
            return self::DIFFERENCE_LABELS[self::DIFF_SAME] . ' (already present)';
        }

        return self::DIFFERENCE_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** Sort rank for "severity" ordering: worst first. */
    private function severityOrder(string $difference): int
    {
        return [self::DIFF_WORSE => 0, self::DIFF_DIFFERENT => 1, self::DIFF_NOT_AT_OUT => 2, self::DIFF_NEW_ITEM => 3, self::DIFF_BETTER => 4, self::DIFF_SAME => 5][$difference] ?? 6;
    }

    private function hasRowFilter(array $filters): bool
    {
        return trim((string) ($filters['q'] ?? '')) !== '' || ! empty($filters['difference']) || ! empty($filters['classification']);
    }

    private function roomMatchesFilters(?PropertyRoom $room, array $filters): bool
    {
        $roomFilter = $filters['room'] ?? null;

        return ! $roomFilter || ($room && (string) $room->id === (string) $roomFilter);
    }

    /** @param array<string, mixed> $r */
    private function rowPasses(array $r, array $filters): bool
    {
        if (! empty($filters['differences_only']) && ! $r['marked']) {
            return false;
        }
        if (! empty($filters['difference']) && $r['difference'] !== $filters['difference']) {
            return false;
        }
        if ($room = ($filters['room'] ?? null)) {
            if ($room === 'general' ? $r['room_id'] !== null : (string) $r['room_id'] !== (string) $room) {
                return false;
            }
        }
        if ($classification = ($filters['classification'] ?? null)) {
            $disposition = $r['finding']?->disposition;
            $ok = match ($classification) {
                'none' => $r['marked'] && $disposition === null,
                'any' => $disposition !== null,
                default => $disposition === $classification,
            };
            if (! $ok) {
                return false;
            }
        }
        if (($q = mb_strtolower(trim((string) ($filters['q'] ?? '')))) !== '') {
            $hay = mb_strtolower(($r['item']->label ?? '') . ' ' . ($r['item']->room?->label ?? '') . ' ' . ($r['in']['note'] ?? '') . ' ' . ($r['out']['note'] ?? ''));
            if (! str_contains($hay, $q)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every live photo of one inspection, split into item photos (through their observation) and room-level general
     * photos — loaded once, serialised for the screen and the viewer (capture caption from I-1, the photo's own note).
     *
     * @return array{items: Collection<int, Collection>, rooms: Collection<int, Collection>}
     */
    private function photosByTarget(RentalInspection $inspection): array
    {
        $photos = RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)
            ->with(['note', 'observation:id,rental_inspection_item_id'])
            ->orderBy('id')
            ->get();

        $serialise = fn (RentalInspectionPhoto $p) => [
            'id' => $p->id,
            'url' => $p->storage_path,
            'caption' => $p->taken_caption,
            'short' => $p->taken_caption_short,
            'note' => $p->note?->note,
        ];

        $items = $photos->filter(fn ($p) => $p->observation?->rental_inspection_item_id)
            ->groupBy(fn ($p) => $p->observation->rental_inspection_item_id)
            ->map(fn ($g) => $g->map($serialise)->values());
        $rooms = $photos->filter(fn ($p) => ! $p->rental_inspection_observation_id && $p->property_room_id)
            ->groupBy('property_room_id')
            ->map(fn ($g) => $g->map($serialise)->values());

        return ['items' => $items, 'rooms' => $rooms];
    }

    /**
     * Pair move-in and move-out photos by their match group (RentalInspectionPhotoMatchGroup — the same links the
     * property tab's viewer shows), paired ones first in the outgoing photo order, then whatever is left on either
     * side. Never invents a pair: an unmatched photo stays alone.
     *
     * @return array<int, array{in: ?array, out: ?array}>
     */
    private function pairPhotos(Collection $inList, Collection $outList, Collection $groupOf): array
    {
        $inLeft = $inList->values()->all();
        $pairs = [];
        $unpairedOut = [];

        foreach ($outList as $outPhoto) {
            $group = $groupOf->get($outPhoto['id']);
            $matchIndex = $group === null ? null : collect($inLeft)->search(fn ($p) => $groupOf->get($p['id']) === $group);
            if ($matchIndex !== null && $matchIndex !== false) {
                $pairs[] = ['in' => $inLeft[$matchIndex], 'out' => $outPhoto];
                unset($inLeft[$matchIndex]);
                $inLeft = array_values($inLeft);
            } else {
                $unpairedOut[] = $outPhoto;
            }
        }
        foreach ($unpairedOut as $outPhoto) {
            $pairs[] = ['in' => null, 'out' => $outPhoto];
        }
        foreach ($inLeft as $inPhoto) {
            $pairs[] = ['in' => $inPhoto, 'out' => null];
        }

        return $pairs;
    }

    /**
     * §45.7a item 3 — what happened to an item between the move-in and the move-out, as one compact timeline per marked
     * row: observations from the tenancy's OTHER inspections (a tenant's fault report, an interim or ad-hoc check) and every
     * fault report and work order raised against the item (status, closed date, who did it). Money is deliberately absent —
     * the finance build owns money.
     *
     * @param  array<int, int>  $itemIds
     * @param  array<int, int>  $leaseIds
     * @param  array<int, ?int>  $excludeInspectionIds the baseline and this out-inspection themselves
     * @return array<int, array<int, array{when: ?\Carbon\CarbonInterface, kind: string, text: string, url: ?string}>>
     */
    private function contextFor(array $itemIds, array $leaseIds, array $excludeInspectionIds): array
    {
        $timeline = [];
        $exclude = array_filter($excludeInspectionIds);

        $observations = RentalInspectionObservation::recorded()
            ->whereIn('rental_inspection_item_id', $itemIds)
            ->whereIn('source', [RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT, RentalInspectionObservation::SOURCE_AD_HOC])
            ->whereIn('rental_inspection_id', RentalInspection::withTrashed()->whereIn('lease_id', $leaseIds)->select('id'))
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('rental_inspection_id', $exclude))
            ->with(['inspection:id,type'])
            ->orderBy('created_at')->get();
        foreach ($observations as $o) {
            $who = $o->source === RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT
                ? 'Reported by the tenant'
                : (($o->inspection?->type === RentalInspection::TYPE_INTERIM ? 'Interim inspection' : 'Check during the tenancy'));
            $condition = $this->stateFor($o->agency_id, $o->condition)['label'] ?? ucfirst(str_replace('_', ' ', $o->condition));
            $timeline[$o->rental_inspection_item_id][] = [
                'when' => $o->created_at, 'kind' => 'observation',
                'text' => "{$who}: {$condition}" . ($o->notes ? ' — ' . $o->notes : ''),
                'url' => null,
            ];
        }

        foreach (\App\Models\RentalFaultReport::whereIn('lease_id', $leaseIds)->whereIn('rental_inspection_item_id', $itemIds)->orderBy('reported_at')->get() as $f) {
            $text = 'Fault report: ' . $f->title . ' (' . ucfirst(str_replace('_', ' ', $f->status)) . ')';
            if ($f->resolved_at) {
                $text .= ' — closed ' . $f->resolved_at->format('j M Y');
            }
            $timeline[$f->rental_inspection_item_id][] = [
                'when' => $f->reported_at ?? $f->created_at, 'kind' => 'fault_report', 'text' => $text,
                'url' => route('corex.rental-fault-reports.show', $f),
            ];
        }

        foreach (\App\Models\RentalWorkOrder::whereIn('lease_id', $leaseIds)->whereIn('rental_inspection_item_id', $itemIds)->with('supplier')->orderBy('reported_at')->get() as $w) {
            $who = $w->assignment_type === \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL ? 'our team' : ($w->supplier?->name ?? 'a supplier not yet appointed');
            $text = 'Work order: ' . $w->title . ' (' . ucfirst(str_replace('_', ' ', $w->status)) . ')';
            if ($w->completed_at) {
                $text .= ' — completed ' . $w->completed_at->format('j M Y') . ' by ' . $who;
            } elseif ($w->status !== \App\Models\RentalWorkOrder::STATUS_REPORTED) {
                $text .= ' — with ' . $who;
            }
            $timeline[$w->rental_inspection_item_id][] = [
                'when' => $w->reported_at ?? $w->created_at, 'kind' => 'work_order', 'text' => $text,
                'url' => route('corex.rental-work-orders.show', $w),
            ];
        }

        foreach ($timeline as &$entries) {
            usort($entries, fn ($a, $b) => ($a['when']?->timestamp ?? 0) <=> ($b['when']?->timestamp ?? 0));
        }
        unset($entries);

        return $timeline;
    }

    /**
     * §4.1/cc6's landed shape — the header-block facts (keys, remotes,
     * meter readings) compared the same way item conditions are: read
     * directly off the two RentalInspection rows by name. Safe to call
     * before those columns exist anywhere in the schema — Eloquent
     * attribute access on an absent column returns null rather than
     * erroring, so this degrades to "nothing to compare yet" automatically
     * and activates the moment cc6's migration lands, with no change
     * needed here.
     *
     * @return array<int, array{label: string, in: mixed, out: mixed, changed: bool}>
     */
    public function compareHeaderFacts(?RentalInspection $inInspection, RentalInspection $outInspection): array
    {
        $pairs = [
            ['label' => 'Keys', 'count' => 'keys_count', 'description' => 'keys_description'],
            ['label' => 'Remotes', 'count' => 'remotes_count', 'description' => 'remotes_description'],
        ];

        $rows = [];
        foreach ($pairs as $pair) {
            $inCount = $inInspection?->getAttribute($pair['count']);
            $outCount = $outInspection->getAttribute($pair['count']);
            $inDescription = $inInspection?->getAttribute($pair['description']);
            $outDescription = $outInspection->getAttribute($pair['description']);

            if ($inCount === null && $outCount === null && $inDescription === null && $outDescription === null) {
                continue;
            }

            $rows[] = [
                'label' => $pair['label'],
                'in' => $inCount !== null ? $inCount : $inDescription,
                'out' => $outCount !== null ? $outCount : $outDescription,
                // A description-only difference (no counts recorded on
                // either side) is never flagged as "changed" — free text
                // rarely matches verbatim between two independent
                // walkthroughs, and that alone isn't evidence of anything.
                'changed' => $inCount !== null && $outCount !== null && $inCount !== $outCount,
            ];
        }

        foreach (['electricity_meter_reading' => 'Electricity meter', 'water_meter_reading' => 'Water meter'] as $column => $label) {
            $inValue = $inInspection?->getAttribute($column);
            $outValue = $outInspection->getAttribute($column);
            if ($inValue === null && $outValue === null) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'in' => $inValue,
                'out' => $outValue,
                // Meter readings are free text ("BODY CORP" is a real,
                // valid value, §2.1 of the spec) — never presumed
                // chargeable just because the strings differ; surfaced for
                // the agent to read, not classified as declined/improved.
                'changed' => $inValue !== null && $outValue !== null && $inValue !== $outValue,
            ];
        }

        return $rows;
    }

    /**
     * Record an agent's wear-and-tear/flagged judgement on one item. Only
     * valid against a 'declined' classification — marking a judgement on
     * an item that was never a candidate (unchanged/improved/N-A) has
     * nothing to judge.
     */
    public function recordFinding(RentalInspection $outInspection, RentalInspectionItem $item, string $disposition, string $note, \App\Models\User $recordedBy): RentalInspectionItemFinding
    {
        $row = $this->compareItems($outInspection)->firstWhere(fn (array $r) => $r['item']?->id === $item->id);
        if (! $row || ! in_array($row['classification'], self::REVIEWABLE_CLASSIFICATIONS, true)) {
            throw new \LogicException('This item is not a declined finding on this out-inspection — nothing to record a judgement against.');
        }

        return RentalInspectionItemFinding::record($outInspection, $item, $disposition, $note, $recordedBy);
    }
}
