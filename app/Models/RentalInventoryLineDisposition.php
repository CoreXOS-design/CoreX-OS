<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inventory.md §8 — the move-out finding for one line.
 * APPEND-ONLY: never updated, never deleted, no `update()` method exists on
 * this class by design. A correction is a NEW row, same discipline as
 * §16's supersede-never-edit for wet-ink evidence, except there is no
 * "superseded_at" marker to set here — RentalInventoryLine::
 * latestDisposition() simply reads the most recent row as current, and
 * every prior row stays exactly as filed, forming the audit trail.
 */
class RentalInventoryLineDisposition extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'rental_inventory_line_id',
        'rental_inventory_id',
        'disposition_key',
        'quantity_found',
        'notes',
        'recorded_by_user_id',
        'recorded_at',
    ];

    protected $casts = [
        'quantity_found' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(RentalInventoryLine::class, 'rental_inventory_line_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(RentalInventory::class, 'rental_inventory_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * The ONE place this table's invariants are enforced — same discipline
     * as every other capture() in this codebase tonight.
     * - Only recordable once the inventory itself is completed (signed,
     *   final) — a move-out finding compared against a baseline that isn't
     *   final yet is comparing against nothing settled.
     * - `quantity_found` is genuinely optional (Johan: "a tenant must never
     *   be charged for something nobody counted") — never defaulted to 0
     *   here or anywhere that reads this table.
     * - `notes` is required when the agency's own preset for this
     *   disposition_key has requires_notes=true (damaged/missing by
     *   default) — same "mandatory reason, agency-configurable which keys
     *   need it" shape as §15.5's refusal capture.
     */
    public static function record(RentalInventoryLine $line, string $dispositionKey, array $attributes = []): self
    {
        $inventory = $line->inventory;

        if ($inventory->status !== RentalInventory::STATUS_COMPLETED) {
            throw new \LogicException('Cannot record a move-out finding until the inventory itself is completed.');
        }

        $presets = RentalInventorySetting::dispositionPresetsFor($inventory->agency_id);
        $preset = collect($presets)->firstWhere('key', $dispositionKey);
        if (! $preset) {
            throw new \InvalidArgumentException("Unknown disposition_key: {$dispositionKey}");
        }
        if (($preset['requires_notes'] ?? false) && empty($attributes['notes'])) {
            throw new \InvalidArgumentException("The \"{$preset['label']}\" disposition requires notes.");
        }

        return self::create(array_merge($attributes, [
            'agency_id' => $inventory->agency_id,
            'rental_inventory_line_id' => $line->id,
            'rental_inventory_id' => $inventory->id,
            'disposition_key' => $dispositionKey,
            'recorded_at' => $attributes['recorded_at'] ?? now(),
        ]));
    }
}
