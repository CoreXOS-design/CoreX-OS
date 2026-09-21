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
     * summary — never a computed charge, never a currency figure.
     *
     * @return array<int, array{
     *   line_id: int, room_label: string, description: string,
     *   quantity_at_move_in: int, quantity_found: ?int,
     *   quantity_delta: ?int, disposition_key: ?string,
     *   disposition_label: ?string, notes: ?string, recorded_at: ?string,
     *   recorded_by: ?string, outstanding: bool,
     * }>
     */
    public function compare(RentalInventory $inventory): array
    {
        $presets = collect(RentalInventorySetting::dispositionPresetsFor($inventory->agency_id));

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

        return $inventory->lines->map(function ($line) use ($presets, $latestByLine) {
            $finding = $latestByLine->get($line->id);

            // §8 — quantity_found is never coerced to 0. Absent means "not
            // yet counted," and the delta is only ever computed when a real
            // count exists — a null quantity_found produces a null delta,
            // never a false "all missing" reading.
            $quantityFound = $finding?->quantity_found;
            $delta = $quantityFound !== null ? ($line->quantity - $quantityFound) : null;

            $preset = $finding ? $presets->firstWhere('key', $finding->disposition_key) : null;

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
            ];
        })->all();
    }
}
