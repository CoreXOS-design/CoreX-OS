<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\PropertyRoom;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionPhotoMatchGroupMember;
use App\Models\RentalInspectionSetting;
use App\Models\RentalWorkOrder;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-inspection-form.md §7 + .ai/specs/rental-inspections.md §45.7a (Build I-7) — the
 * move-out comparison the paper form's own printed instructions say is the entire point of doing an
 * in-inspection at all: "...uses the move-in checklist during the pre-move out inspection and again when
 * determaning if any of the tenant's deposit will be retained..."
 *
 * Produces PROPOSED findings only — item + both conditions + both sets of notes/photos + a classification
 * an agent reviews. Nothing here computes an amount, a currency value, or a deduction: money belongs to
 * the finance build (parked I-8). The row marks are DERIVED at read time and never stored (§3.1); the
 * only thing persisted is a human judgement (recordFinding()).
 *
 * Every method is read-only over existing data except recordFinding().
 */
class RentalInspectionComparisonService
{
    /**
     * cc2 (31ec28ae1, N/A + agency-configurable condition vocabulary) shipped N/A as a FIXED key,
     * `'n_a'`, regardless of an agency's own condition vocabulary — RentalInspectionRecordingController::
     * markRoomNa() writes this exact literal. The vocabulary itself (which OTHER states exist, their labels,
     * their severity) is agency-configurable per RentalInspectionSetting::conditionStatesFor().
     */
    private const CONDITION_NA = 'n_a';

    public const ONLY_AT_IN = 'only_at_in';
    public const ONLY_AT_OUT = 'only_at_out';
    public const NA_BOTH = 'na_both';
    public const NA_MISMATCH = 'na_mismatch';
    public const UNCHANGED = 'unchanged';
    public const IMPROVED = 'improved';
    /** "Worse than move-in" — the out condition sits in a worse severity bucket than the move-in one. */
    public const DECLINED = 'declined';
    /** Same severity bucket, different state (e.g. Damaged -> Missing, Fair -> Good): marked, never auto-called worse. */
    public const DIFFERENT = 'different';

    /**
     * §45.7a — "a finding can now be recorded on any MARKED row". A row is marked when it is worse,
     * different, exists on one side only, or is N/A on one side only. Better and Same are not marked.
     */
    public const REVIEWABLE_CLASSIFICATIONS = [
        self::DECLINED,
        self::DIFFERENT,
        self::NA_MISMATCH,
        self::ONLY_AT_IN,
        self::ONLY_AT_OUT,
    ];

    /**
     * The words on the row mark (§45.7a). Facts about the two recordings, never a statement about anyone's
     * rights. UNCHANGED gets its " (already present)" tail from markLabelFor() when the shared state is a
     * problem state.
     */
    public const MARK_LABELS = [
        self::DECLINED => 'Worse than move-in',
        self::DIFFERENT => 'Different from move-in',
        self::UNCHANGED => 'Same as move-in',
        self::IMPROVED => 'Better',
        self::ONLY_AT_OUT => 'New item',
        self::ONLY_AT_IN => 'Not at move-out',
        self::NA_MISMATCH => 'N/A on one side only',
    ];

    /** Order for the "Severity" sort — the rows an agent most needs to look at first. */
    private const MARK_RANK = [
        self::DECLINED => 6,
        self::ONLY_AT_IN => 5,
        self::DIFFERENT => 4,
        self::NA_MISMATCH => 3,
        self::ONLY_AT_OUT => 2,
        self::UNCHANGED => 0,
        self::IMPROVED => 0,
    ];

    /** The agency severity buckets, calm -> bad. `grey` is deliberately absent: it is "not a grade". */
    private const SEVERITY_RANK = ['blue' => 0, 'amber' => 1, 'red' => 2];

    /** The filter values the page offers — anything else is absorbed to the default, never a 500. */
    public const DIFFERENCE_FILTERS = ['all', 'worse', 'different', 'same', 'better', 'new', 'not_at_out', 'na'];

    private const DIFFERENCE_TO_CLASSIFICATION = [
        'worse' => self::DECLINED,
        'different' => self::DIFFERENT,
        'same' => self::UNCHANGED,
        'better' => self::IMPROVED,
        'new' => self::ONLY_AT_OUT,
        'not_at_out' => self::ONLY_AT_IN,
        'na' => self::NA_MISMATCH,
    ];

    /**
     * Every lease in this tenancy, newest first: the lease itself, then the lease it renewed, and so on
     * back along `previous_lease_id`. A renewal never re-does the incoming inspection, so the move-in
     * evidence lives on the FIRST lease of the chain, not on the lease the tenant is moving out of.
     * Cycle-safe and depth-capped: a corrupt chain is absorbed, never an endless loop.
     *
     * @return array<int, int>
     */
    public function tenancyLeaseIds(?Lease $lease, ?int $fallbackLeaseId = null): array
    {
        $ids = [];
        $seen = [];
        $current = $lease;
        $guard = 0;
        while ($current && ! isset($seen[$current->id]) && $guard++ < 50) {
            $seen[$current->id] = true;
            $ids[] = (int) $current->id;
            $current = $current->previous_lease_id
                ? Lease::withTrashed()->find($current->previous_lease_id)
                : null;
        }

        return $ids !== [] ? $ids : array_values(array_filter([$fallbackLeaseId]));
    }

    /**
     * The tenancy's incoming inspection for this out-inspection, if one exists — resolved from the lease
     * chain (never from a posted id). Two real corrections, both found by verifying directly against real
     * QA1 data (lease 5, property 1724), not assumed:
     *
     * 1. A naive "latest by id" is wrong — a real lease can have MULTIPLE in-inspection rows (an agent
     *    restarted it, leaving abandoned drafts with zero observations alongside the one actually
     *    completed with real evidence). Prefers the most recent COMPLETED in-inspection; only falls back
     *    to the most recent of any status if none was ever completed (the out-inspection is being done
     *    before the in-inspection was formally closed out — still worth comparing against whatever was
     *    recorded, per Johan's own "both are recorded either way" ruling, rental-inspections.md §0.1).
     * 2. Must search archived (soft-deleted) in-inspections too — archiving hides an inspection from the
     *    working list; it must never hide its evidence from a comparison that depends on it.
     */
    public function matchingInInspection(RentalInspection $outInspection): ?RentalInspection
    {
        $leaseIds = $this->tenancyLeaseIds($outInspection->lease, $outInspection->lease_id);
        if ($leaseIds === []) {
            return null;
        }

        $base = RentalInspection::withTrashed()
            ->whereIn('lease_id', $leaseIds)
            ->where('type', RentalInspection::TYPE_IN);

        return (clone $base)->where('status', RentalInspection::STATUS_COMPLETED)->latest('id')->first()
            ?? $base->latest('id')->first();
    }

    /**
     * The full item-by-item comparison for one out-inspection against its tenancy's in-inspection.
     * Returns one entry per item that has AT LEAST ONE graded (or otherwise present) observation on either
     * side — an item with observations on neither side never existed as far as this tenancy is concerned.
     *
     * Item matching is by rental_inspection_item_id — items are property-scoped and reused across every
     * inspection on that property, so the SAME room/space is the SAME row on both inspections as long as
     * the agent picked it from the existing list. There is no fuzzy label matching, deliberately: two
     * different item rows for one physical room surface as one only_at_in row and one only_at_out row —
     * the honest signal that something needs a human's eyes (rental-inspections.md §7.2).
     *
     * @return Collection<int, array{
     *   item: RentalInspectionItem,
     *   classification: string,
     *   in_observation: ?RentalInspectionObservation,
     *   out_observation: ?RentalInspectionObservation,
     *   finding: ?RentalInspectionItemFinding,
     * }>
     */
    public function compareItems(RentalInspection $outInspection, ?RentalInspection $inInspection = null): Collection
    {
        $this->stateCache = [];
        $inInspection ??= $this->matchingInInspection($outInspection);

        // AT-433, 2026-09-26 — ->recorded() excludes photo-anchor rows (CONDITION_PENDING) from both
        // sides: an item with only an unrecorded photo has nothing to compare yet.
        //
        // §45.7a — a TENANT FAULT REPORT filed on the in-inspection after it completed is not part of the
        // move-in recording; it is CONTEXT for the row (context()), never the baseline condition.
        $inObservations = $inInspection
            ? RentalInspectionObservation::recorded()
                ->where('rental_inspection_id', $inInspection->id)
                ->where('source', '!=', RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT)
                ->with('observedByUser')
                ->orderBy('rental_inspection_item_id')
                ->latest('created_at')
                ->latest('id')
                ->get()
                ->unique('rental_inspection_item_id')
                ->keyBy('rental_inspection_item_id')
            : collect();

        $outObservations = RentalInspectionObservation::recorded()
            ->where('rental_inspection_id', $outInspection->id)
            ->with('observedByUser')
            ->orderBy('rental_inspection_item_id')
            ->latest('created_at')
            ->latest('id')
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
            ->with('recordedBy')
            ->get()
            ->keyBy('rental_inspection_item_id');

        $agencyId = $outInspection->agency_id;

        return $itemIds->map(function ($itemId) use ($items, $inObservations, $outObservations, $findings, $agencyId) {
            $inObs = $inObservations->get($itemId);
            $outObs = $outObservations->get($itemId);

            return [
                'item' => $items->get($itemId),
                'classification' => $this->classify($inObs, $outObs, $agencyId),
                'in_observation' => $inObs,
                'out_observation' => $outObs,
                'finding' => $findings->get($itemId),
            ];
        })
            // §7.1 — N/A on both sides is not a finding at all, not even a displayed-but-excluded row.
            ->reject(fn (array $row) => $row['classification'] === self::NA_BOTH)
            ->values();
    }

    /**
     * §45.7a — derived at read time from the AGENCY's own condition vocabulary (severity bucket), never a
     * hard-coded 'good' test: an agency whose baseline state is called "OK" or "Excellent" classifies
     * exactly like one that uses "Good".
     */
    private function classify(?RentalInspectionObservation $in, ?RentalInspectionObservation $out, ?int $agencyId): string
    {
        if (! $in && ! $out) {
            // Cannot happen given compareItems()'s own item-id union, kept as an explicit, honest branch.
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
        if ($in->condition === $out->condition) {
            return self::UNCHANGED;
        }

        $inRank = $this->severityRank($agencyId, $in->condition);
        $outRank = $this->severityRank($agencyId, $out->condition);
        if ($inRank !== null && $outRank !== null) {
            if ($outRank > $inRank) {
                return self::DECLINED;
            }
            if ($outRank < $inRank) {
                return self::IMPROVED;
            }
        }

        // Same bucket (or a state outside the calm/amber/red scale) with a different state: surfaced for a
        // human, never silently dropped and never auto-called worse.
        return self::DIFFERENT;
    }

    /**
     * The agency's condition vocabulary, read ONCE per comparison. conditionSeverityFor() re-reads the
     * settings row on every call; a 150-item move-out would make hundreds of identical queries.
     * The cache is reset at the start of every public entry point so a settings change between two calls
     * on the same instance is never served stale.
     *
     * @var array<int, Collection>
     */
    private array $stateCache = [];

    private function states(?int $agencyId): Collection
    {
        return $this->stateCache[$agencyId ?? 0] ??= collect(RentalInspectionSetting::conditionStatesFor($agencyId))->keyBy('key');
    }

    /** Same resolution as RentalInspectionSetting::conditionSeverityFor(): an unknown state is `red` — never hidden. */
    private function severityOf(?int $agencyId, string $conditionKey): string
    {
        $severity = $this->states($agencyId)->get($conditionKey)['severity'] ?? 'red';

        return array_key_exists($severity, RentalInspectionSetting::SEVERITY_COLORS) ? $severity : 'red';
    }

    private function severityRank(?int $agencyId, string $conditionKey): ?int
    {
        return self::SEVERITY_RANK[$this->severityOf($agencyId, $conditionKey)] ?? null;
    }

    /** True for a state that is a problem state in the agency's own vocabulary (amber/red). */
    private function isProblemState(?int $agencyId, string $conditionKey): bool
    {
        return in_array($this->severityOf($agencyId, $conditionKey), ['amber', 'red'], true);
    }

    /** "Same as move-in (already present)" for a shared problem state; the plain mark word otherwise. */
    public function markLabelFor(string $classification, ?RentalInspectionObservation $in, ?int $agencyId): string
    {
        $label = self::MARK_LABELS[$classification] ?? ucfirst(str_replace('_', ' ', $classification));
        if ($classification === self::UNCHANGED && $in && $this->isProblemState($agencyId, $in->condition)) {
            $label .= ' (already present)';
        }

        return $label;
    }

    /**
     * §45.7a — the whole move-out comparison page in one array: baseline resolved from the lease chain,
     * one row per item (condition in the agency's own labels, notes, photos with capture times, photo notes,
     * paired photos first), rows grouped by room in walking order, general room photos both sides, a
     * context timeline on every marked row, and the filters applied. Everything is eager-loaded in a fixed
     * number of queries — no per-row N+1 (BUILD_STANDARD §4).
     *
     * @param array<string, mixed> $rawFilters straight from the query string — normalised here, never trusted
     */
    public function buildComparison(RentalInspection $out, array $rawFilters = []): array
    {
        $this->stateCache = [];
        $agencyId = $out->agency_id;
        $in = $this->matchingInInspection($out);
        $leaseIds = $this->tenancyLeaseIds($out->lease, $out->lease_id);
        $rows = $this->compareItems($out, $in);

        $states = $this->states($agencyId);
        $classifications = collect(RentalInspectionSetting::photoNoteClassificationsFor($agencyId))->keyBy('key');
        $itemIds = $rows->pluck('item.id')->filter()->values();

        $photoSets = $this->loadPhotos($in, $out, $itemIds, $classifications);
        $contexts = $this->loadContext($in, $out, $leaseIds, $rows, $states);

        $built = $rows->map(function (array $row) use ($agencyId, $states, $photoSets, $contexts) {
            $item = $row['item'];
            $classification = $row['classification'];
            $marked = in_array($classification, self::REVIEWABLE_CLASSIFICATIONS, true);
            $itemId = $item?->id;
            [$inPhotos, $outPhotos] = $photoSets['items'][$itemId] ?? [[], []];

            return $row + [
                'room' => $item?->room,
                'marked' => $marked,
                'mark_label' => $this->markLabelFor($classification, $row['in_observation'], $agencyId),
                'rank' => self::MARK_RANK[$classification] ?? 0,
                'in_cell' => $this->cell($row['in_observation'], $states, $inPhotos),
                'out_cell' => $this->cell($row['out_observation'], $states, $outPhotos),
                'context' => $marked ? ($contexts[$itemId] ?? ['observations' => [], 'fault_reports' => [], 'work_orders' => []]) : null,
                'already_present' => $classification === self::UNCHANGED
                    && $row['in_observation'] !== null
                    && $this->isProblemState($agencyId, $row['in_observation']->condition),
            ];
        });

        $counts = [
            'total' => $built->count(),
            'marked' => $built->where('marked', true)->count(),
            'worse' => $built->where('classification', self::DECLINED)->count(),
            'different' => $built->where('classification', self::DIFFERENT)->count(),
            'same' => $built->where('classification', self::UNCHANGED)->count(),
            'better' => $built->where('classification', self::IMPROVED)->count(),
            'new' => $built->where('classification', self::ONLY_AT_OUT)->count(),
            'not_at_out' => $built->where('classification', self::ONLY_AT_IN)->count(),
            'na' => $built->where('classification', self::NA_MISMATCH)->count(),
            'unclassified_marked' => $built->where('marked', true)->filter(fn ($r) => $r['finding'] === null)->count(),
        ];

        $filters = $this->normaliseFilters($rawFilters);
        $filtered = $built->filter(fn (array $row) => $this->passes($row, $filters))->values();

        return [
            'baseline' => $in,
            'filters' => $filters,
            'counts' => $counts,
            'shown' => $filtered->count(),
            'groups' => $this->groupByRoom($filtered, $photoSets['rooms'], $filters['sort']),
            'roomOptions' => $built->map(fn ($r) => $r['room'])->filter()->unique('id')
                ->sortBy(fn (PropertyRoom $r) => [$r->sort_order, $r->id])
                ->mapWithKeys(fn (PropertyRoom $r) => [$r->id => $r->label])->all(),
            'hasUnroomed' => $built->contains(fn ($r) => $r['room'] === null),
            'conditionStates' => $states,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{q: string, room: string, difference: string, classification: string, only_differences: bool, sort: string}
     */
    public function normaliseFilters(array $raw): array
    {
        $q = is_scalar($raw['q'] ?? null) ? trim((string) $raw['q']) : '';
        $room = is_scalar($raw['room'] ?? null) ? trim((string) $raw['room']) : '';
        $difference = is_scalar($raw['difference'] ?? null) ? (string) $raw['difference'] : 'all';
        $classification = is_scalar($raw['classification'] ?? null) ? (string) $raw['classification'] : 'all';
        $sort = is_scalar($raw['sort'] ?? null) ? (string) $raw['sort'] : 'walking';

        return [
            'q' => mb_substr($q, 0, 100),
            'room' => ($room === 'none' || ctype_digit($room)) ? $room : '',
            'difference' => in_array($difference, self::DIFFERENCE_FILTERS, true) ? $difference : 'all',
            'classification' => ($classification === 'unclassified' || array_key_exists($classification, RentalInspectionItemFinding::DISPOSITION_LABELS))
                ? $classification : 'all',
            'only_differences' => filter_var($raw['only_differences'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sort' => in_array($sort, ['walking', 'severity'], true) ? $sort : 'walking',
        ];
    }

    private function passes(array $row, array $f): bool
    {
        if ($f['only_differences'] && ! $row['marked']) {
            return false;
        }
        if ($f['difference'] !== 'all' && $row['classification'] !== self::DIFFERENCE_TO_CLASSIFICATION[$f['difference']]) {
            return false;
        }
        if ($f['room'] !== '') {
            $roomId = $row['room']?->id;
            if ($f['room'] === 'none' ? $roomId !== null : (string) $roomId !== $f['room']) {
                return false;
            }
        }
        if ($f['classification'] === 'unclassified') {
            if (! $row['marked'] || $row['finding'] !== null) {
                return false;
            }
        } elseif ($f['classification'] !== 'all' && $row['finding']?->disposition !== $f['classification']) {
            return false;
        }
        if ($f['q'] !== '') {
            $haystack = implode(' ', array_filter([
                $row['room']?->label, $row['item']?->label,
                $row['in_observation']?->notes, $row['out_observation']?->notes,
                $row['in_cell']['label'] ?? null, $row['out_cell']['label'] ?? null,
                $row['finding']?->note,
            ]));
            if (mb_stripos($haystack, $f['q']) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rooms in the property's own walking order (property_rooms.sort_order, then id), each carrying its
     * rows, the room's general photos for both sides, and a marked count. "Severity" sort orders the
     * worst rooms and the worst rows first; "walking" is the default and the order an agent actually
     * walks the property in.
     *
     * @return array<int, array{room: ?PropertyRoom, label: string, rows: Collection, in_photos: array, out_photos: array, marked: int, worst: int}>
     */
    private function groupByRoom(Collection $rows, array $roomPhotos, string $sort): array
    {
        $groups = $rows->groupBy(fn (array $r) => $r['room']?->id ?? 0)->map(function (Collection $groupRows, $roomId) use ($roomPhotos, $sort) {
            $room = $groupRows->first()['room'];
            $ordered = $groupRows->sortBy(fn (array $r) => $sort === 'severity'
                ? [-$r['rank'], $r['item']?->sort_order ?? 0, $r['item']?->id ?? 0]
                : [$r['item']?->sort_order ?? 0, $r['item']?->id ?? 0])->values();
            [$inPhotos, $outPhotos] = $roomPhotos[$roomId] ?? [[], []];

            return [
                'room' => $room,
                'label' => $room?->label ?? 'General',
                'rows' => $ordered,
                'in_photos' => $inPhotos,
                'out_photos' => $outPhotos,
                'marked' => $groupRows->where('marked', true)->count(),
                'worst' => $groupRows->max('rank') ?? 0,
            ];
        });

        return $groups->sortBy(fn (array $g) => $sort === 'severity'
            ? [-$g['worst'], $g['room']?->sort_order ?? PHP_INT_MAX, $g['room']?->id ?? PHP_INT_MAX]
            : [$g['room'] ? 0 : 1, $g['room']?->sort_order ?? 0, $g['room']?->id ?? 0])->values()->all();
    }

    /** One side of one row, in the agency's own words and severity colour. */
    private function cell(?RentalInspectionObservation $obs, Collection $states, array $photos): array
    {
        if (! $obs) {
            return ['present' => false, 'key' => null, 'label' => null, 'color' => null, 'notes' => null, 'by' => null, 'photos' => $photos];
        }
        $state = $states->get($obs->condition);
        $severity = $this->severityOf($obs->agency_id, $obs->condition);

        return [
            'present' => true,
            'key' => $obs->condition,
            // An agency that has since removed/renamed a state still sees the stored key (never blank).
            'label' => $state['label'] ?? ucfirst(str_replace('_', ' ', $obs->condition)),
            'color' => RentalInspectionSetting::SEVERITY_COLORS[$severity] ?? RentalInspectionSetting::SEVERITY_COLORS['red'],
            'notes' => $obs->notes,
            'by' => $obs->observedByUser?->name,
            'photos' => $photos,
        ];
    }

    /**
     * Both inspections' photos for every compared item and room, with capture times, photo notes and
     * match-group ids, in ONE query set. Paired photos (a photo whose match group also has a member on the
     * other side) are listed first — the same paired-first rule the property tab's viewer uses.
     *
     * @return array{items: array<int, array{0: array, 1: array}>, rooms: array<int, array{0: array, 1: array}>}
     */
    private function loadPhotos(?RentalInspection $in, RentalInspection $out, Collection $itemIds, Collection $classifications): array
    {
        $inspectionIds = array_values(array_filter([$in?->id, $out->id]));

        $observationItem = RentalInspectionObservation::whereIn('rental_inspection_id', $inspectionIds)
            ->whereIn('rental_inspection_item_id', $itemIds)
            ->pluck('rental_inspection_item_id', 'id');

        $photos = RentalInspectionPhoto::whereIn('rental_inspection_id', $inspectionIds)
            ->where(fn ($q) => $q->whereIn('rental_inspection_observation_id', $observationItem->keys())
                ->orWhere(fn ($r) => $r->whereNull('rental_inspection_observation_id')->whereNotNull('property_room_id')))
            ->with('note')
            ->orderBy('taken_at')->orderBy('id')
            ->get();

        $groupOf = RentalInspectionPhotoMatchGroupMember::whereIn('rental_inspection_photo_id', $photos->pluck('id'))
            ->whereHas('group')
            ->pluck('rental_inspection_photo_match_group_id', 'rental_inspection_photo_id');

        // A group "has a counterpart" when it has members on BOTH inspections.
        $groupSides = [];
        foreach ($photos as $photo) {
            $gid = $groupOf->get($photo->id);
            if ($gid) {
                $groupSides[$gid][$photo->rental_inspection_id === $out->id ? 'out' : 'in'] = true;
            }
        }

        $shape = function (RentalInspectionPhoto $photo) use ($groupOf, $groupSides, $classifications): array {
            $gid = $groupOf->get($photo->id);
            $noteClass = $photo->note?->classification_key;

            return [
                'id' => $photo->id,
                // storage_path is already a full URL (same as every other view that renders a photo).
                'src' => $photo->storage_path,
                'caption' => $photo->taken_caption,
                'caption_short' => $photo->taken_caption_short,
                'note' => $photo->note?->note,
                'note_class' => $noteClass ? ($classifications->get($noteClass)['label'] ?? null) : null,
                'group' => $gid,
                'paired' => $gid && ! empty($groupSides[$gid]['in']) && ! empty($groupSides[$gid]['out']),
            ];
        };

        $items = [];
        $rooms = [];
        foreach ($photos as $photo) {
            $side = ($in && $photo->rental_inspection_id === $in->id) ? 0 : 1;
            if ($photo->rental_inspection_observation_id) {
                $itemId = $observationItem->get($photo->rental_inspection_observation_id);
                if ($itemId !== null) {
                    $items[$itemId][$side][] = $shape($photo);
                }
            } else {
                $rooms[$photo->property_room_id][$side][] = $shape($photo);
            }
        }

        $pairedFirst = fn (array $list) => collect($list)->sortBy(fn ($p) => $p['paired'] ? 0 : 1)->values()->all();
        $fill = fn (array $sides) => [$pairedFirst($sides[0] ?? []), $pairedFirst($sides[1] ?? [])];

        return [
            'items' => array_map($fill, $items),
            'rooms' => array_map($fill, $rooms),
        ];
    }

    /**
     * §45.7a item 3 — what happened to this item BETWEEN the two inspections, on the same row: tenant
     * fault reports and interim / ad-hoc recordings, plus every fault report and work order raised against
     * the item (status, when closed, who did it). Money is deliberately NOT read — I-8 / the finance build
     * owns it. Only marked rows need this, so only marked rows are looked up.
     *
     * @return array<int, array{observations: array, fault_reports: array, work_orders: array}>
     */
    private function loadContext(?RentalInspection $in, RentalInspection $out, array $leaseIds, Collection $rows, Collection $states): array
    {
        $markedItemIds = $rows
            ->filter(fn (array $r) => in_array($r['classification'], self::REVIEWABLE_CLASSIFICATIONS, true))
            ->pluck('item.id')->filter()->values();
        if ($markedItemIds->isEmpty()) {
            return [];
        }

        $betweenInspections = RentalInspection::withTrashed()
            ->whereIn('lease_id', $leaseIds)
            ->whereNotIn('id', array_values(array_filter([$in?->id, $out->id])))
            ->get(['id', 'type'])
            ->keyBy('id');

        $observations = RentalInspectionObservation::recorded()
            ->whereIn('rental_inspection_item_id', $markedItemIds)
            ->where(function ($q) use ($betweenInspections, $in) {
                $q->whereIn('rental_inspection_id', $betweenInspections->keys());
                if ($in) {
                    $q->orWhere(fn ($r) => $r->where('rental_inspection_id', $in->id)
                        ->where('source', RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT));
                }
            })
            ->with('observedByUser')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $faultReports = RentalFaultReport::whereIn('rental_inspection_item_id', $markedItemIds)
            ->whereIn('lease_id', $leaseIds)
            ->orderBy('reported_at')
            ->get();

        $workOrders = RentalWorkOrder::with('supplier')
            ->whereIn('rental_inspection_item_id', $markedItemIds)
            ->whereIn('lease_id', $leaseIds)
            ->orderBy('reported_at')
            ->get();

        $out = [];
        foreach ($markedItemIds as $itemId) {
            $out[$itemId] = [
                'observations' => $observations->where('rental_inspection_item_id', $itemId)->map(function (RentalInspectionObservation $o) use ($states, $betweenInspections) {
                    $kind = $o->source === RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT
                        ? 'Tenant fault report'
                        : match ($betweenInspections->get($o->rental_inspection_id)?->type) {
                            'interim' => 'Interim inspection',
                            RentalInspection::TYPE_AD_HOC => 'Ad-hoc check',
                            default => 'Inspection',
                        };

                    return [
                        'when' => $o->created_at,
                        'kind' => $kind,
                        'condition' => $states->get($o->condition)['label'] ?? ucfirst(str_replace('_', ' ', $o->condition)),
                        'notes' => $o->notes,
                        'by' => $o->observedByUser?->name,
                    ];
                })->values()->all(),
                'fault_reports' => $faultReports->where('rental_inspection_item_id', $itemId)->map(fn (RentalFaultReport $f) => [
                    'id' => $f->id,
                    'title' => $f->title,
                    'status' => ucfirst(str_replace('_', ' ', $f->status)),
                    'reported_at' => $f->reported_at,
                    'closed_at' => $f->resolved_at ?? $f->repaired_at,
                    'outcome' => $f->outcome ? ucfirst(str_replace('_', ' ', $f->outcome)) : null,
                ])->values()->all(),
                'work_orders' => $workOrders->where('rental_inspection_item_id', $itemId)->map(fn (RentalWorkOrder $w) => [
                    'id' => $w->id,
                    'title' => $w->title,
                    'status' => ucfirst(str_replace('_', ' ', $w->status)),
                    'reported_at' => $w->reported_at,
                    'closed_at' => $w->completed_at ?? $w->cancelled_at,
                    'done_by' => $w->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL
                        ? 'Our own team'
                        : ($w->supplier?->name ?? 'Outside supplier'),
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * §4.1/cc6's landed shape — the header-block facts (keys, remotes, meter readings) compared the same
     * way item conditions are: read directly off the two RentalInspection rows by name. Safe to call
     * before those columns exist anywhere in the schema — Eloquent attribute access on an absent column
     * returns null rather than erroring.
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
                // A description-only difference (no counts recorded on either side) is never flagged as
                // "changed" — free text rarely matches verbatim between two independent walkthroughs.
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
                // Meter readings are free text ("BODY CORP" is a real, valid value, §2.1 of the spec) —
                // never presumed chargeable just because the strings differ.
                'changed' => $inValue !== null && $outValue !== null && $inValue !== $outValue,
            ];
        }

        return $rows;
    }

    /**
     * Record or correct an agent's judgement on one item. Valid against any MARKED row (§45.7a) —
     * recording a judgement on an item that is the same, better, or N/A at both ends has nothing to judge.
     */
    public function recordFinding(RentalInspection $outInspection, RentalInspectionItem $item, string $disposition, string $note, \App\Models\User $recordedBy): RentalInspectionItemFinding
    {
        $row = $this->compareItems($outInspection)->firstWhere(fn (array $r) => $r['item']?->id === $item->id);
        if (! $row || ! in_array($row['classification'], self::REVIEWABLE_CLASSIFICATIONS, true)) {
            throw new \LogicException('This item is not marked as a difference on this out-inspection — nothing to record a judgement against.');
        }

        return RentalInspectionItemFinding::record($outInspection, $item, $disposition, $note, $recordedBy);
    }
}
