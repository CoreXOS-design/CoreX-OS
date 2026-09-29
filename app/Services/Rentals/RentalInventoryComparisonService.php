<?php

namespace App\Services\Rentals;

use App\Models\RentalInventory;
use App\Models\RentalInventoryLineDisposition;
use App\Models\RentalInventorySetting;

/**
 * .ai/specs/rental-inventory.md §8 — read-time comparison, never a stored
 * classification, never a currency amount anywhere in the output. Mirrors
 * cc5's own RentalInspectionComparisonService boundary: what this produces
 * is a PROPOSAL an agent reviews, not a deposit decision. If Johan wants
 * inventory and inspection findings combined into one deposit view, that is
 * a later, explicit call — this stays its own thing.
 */
class RentalInventoryComparisonService
{
    /**
     * One row per active (non-retired) line: the move-in baseline, the
     * latest move-out finding if one has been recorded, and a plain-English
     * summary — never a computed charge, never a currency figure. Always
     * anchored on THIS line's own move-in quantity/photos — there is no
     * chain to walk (RentalInventory::start() refuses a second inventory
     * per lease), so this can never repeat rental-inspections' own mistake
     * of comparing against only the immediately-previous record.
     *
     * §11.8 investigation, 2026-09-27 — `photos` is the move-in evidence
     * already tagged to this line during capture (RentalInventoryLine::
     * moveInPhotos()). §14 adds `move_out_photos` (the current side's own
     * evidence, taken from this comparison screen) and `unchanged` (§14 —
     * whether this row needs no argument at all: the agency's own baseline
     * disposition, with a matching or absent quantity_found).
     *
     * @return array<int, array{
     *   line_id: int, room_label: string, description: string,
     *   quantity_at_move_in: int, quantity_found: ?int,
     *   quantity_delta: ?int, disposition_key: ?string,
     *   disposition_label: ?string, notes: ?string, recorded_at: ?string,
     *   recorded_by: ?string, outstanding: bool, unchanged: bool,
     *   photos: array<int, array{id: int, storage_path: string}>,
     *   move_out_photos: array<int, array{id: int, storage_path: string}>,
     * }>
     */
    public function compare(RentalInventory $inventory): array
    {
        $presets = collect(RentalInventorySetting::dispositionPresetsFor($inventory->agency_id));
        $baselineKey = RentalInventorySetting::baselineDispositionKeyFor($inventory->agency_id);

        // Batch-fetch every line's dispositions in ONE query (not
        // latestDisposition() per line, which would be an N+1) and keep only
        // the most recent row per line — same "latest wins" rule, computed
        // in-memory instead of re-querying per line.
        $lineIds = $inventory->lines->pluck('id');
        $latestByLine = RentalInventoryLineDisposition::whereIn('rental_inventory_line_id', $lineIds)
            ->with('recordedByUser')
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->get()
            ->groupBy('rental_inventory_line_id')
            ->map(fn ($group) => $group->first());

        return $inventory->lines->map(function ($line) use ($presets, $latestByLine, $baselineKey) {
            $finding = $latestByLine->get($line->id);

            // §8 — quantity_found is never coerced to 0. Absent means "not
            // yet counted," and the delta is only ever computed when a real
            // count exists — a null quantity_found produces a null delta,
            // never a false "all missing" reading.
            $quantityFound = $finding?->quantity_found;
            $delta = $quantityFound !== null ? ($line->quantity - $quantityFound) : null;

            $preset = $finding ? $presets->firstWhere('key', $finding->disposition_key) : null;

            // §14 — "unchanged lines collapse to one grey line": the
            // agency's own baseline disposition (§14's own
            // baselineDispositionKeyFor()), with a quantity that either
            // wasn't independently counted or matches move-in exactly.
            // Outstanding (no finding at all yet) is a DIFFERENT state —
            // never "unchanged," since nothing has actually been checked.
            $unchanged = $finding !== null
                && $finding->disposition_key === $baselineKey
                && ($quantityFound === null || $quantityFound === $line->quantity);

            return [
                'line_id' => $line->id,
                'room_label' => $line->room_label,
                'description' => $line->description,
                'quantity_at_move_in' => $line->quantity,
                'quantity_found' => $quantityFound,
                'quantity_delta' => $delta,
                'disposition_key' => $finding?->disposition_key,
                'disposition_label' => $preset['label'] ?? $finding?->disposition_key,
                'notes' => $finding?->notes,
                'recorded_at' => $finding?->recorded_at?->format('Y-m-d H:i'),
                'recorded_by' => $finding?->recordedByUser?->name,
                // No finding recorded at all yet — distinct from "recorded, found present."
                'outstanding' => $finding === null,
                'unchanged' => $unchanged,
                // §11.8 — the move-in photos this line was tagged to during
                // capture. Never null/omitted (an empty array renders as no
                // thumbnails, exactly right for a line nobody photographed).
                'photos' => $line->moveInPhotos->map(fn ($p) => ['id' => $p->id, 'storage_path' => $p->storage_path])->values()->all(),
                // §14 — the CURRENT side's own evidence. Deliberately never
                // omitted even when empty: the comparison view must SHOW
                // "no photo taken" rather than silently rendering nothing.
                'move_out_photos' => $line->moveOutPhotos->map(fn ($p) => ['id' => $p->id, 'storage_path' => $p->storage_path])->values()->all(),
            ];
        })->all();
    }
}
