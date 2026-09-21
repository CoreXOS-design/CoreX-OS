<?php

namespace App\Services;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
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

    /** Classifications an agent may record a wear-and-tear/flagged judgement against. */
    public const REVIEWABLE_CLASSIFICATIONS = [self::DECLINED];

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
        $base = RentalInspection::withTrashed()
            ->where('lease_id', $outInspection->lease_id)
            ->where('type', RentalInspection::TYPE_IN);

        return (clone $base)->where('status', RentalInspection::STATUS_COMPLETED)->latest('id')->first()
            ?? $base->latest('id')->first();
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

        $inObservations = $inInspection
            ? RentalInspectionObservation::where('rental_inspection_id', $inInspection->id)
                ->orderBy('rental_inspection_item_id')
                ->latest('created_at')
                ->get()
                ->unique('rental_inspection_item_id')
                ->keyBy('rental_inspection_item_id')
            : collect();

        $outObservations = RentalInspectionObservation::where('rental_inspection_id', $outInspection->id)
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

        return $itemIds->map(function ($itemId) use ($items, $inObservations, $outObservations, $findings) {
            $item = $items->get($itemId);
            $inObs = $inObservations->get($itemId);
            $outObs = $outObservations->get($itemId);

            return [
                'item' => $item,
                'classification' => $this->classify($inObs, $outObs),
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

    private function classify(?RentalInspectionObservation $in, ?RentalInspectionObservation $out): string
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
        if ($in->condition === $out->condition) {
            return self::UNCHANGED;
        }
        if ($out->condition === RentalInspectionObservation::CONDITION_GOOD) {
            return self::IMPROVED;
        }

        // Covers "was good, now isn't" AND "was already not-good, now a
        // DIFFERENT not-good value" (e.g. damaged -> missing). The enum has
        // no reliable severity ordering beyond good/not-good, so any real
        // value change that isn't a move TO good is surfaced as a
        // candidate for the agent to look at directly — never silently
        // dropped. The agent sees both raw conditions, both notes, and
        // both photo sets; this label is a filter to route their
        // attention, not a verdict.
        return self::DECLINED;
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
