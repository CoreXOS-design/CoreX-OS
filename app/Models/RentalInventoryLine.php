<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-inventory.md §3.1/§0b — one real item: "2x White wooden
 * headboards", "4x Remotes - 2x Fans - 2x Aircon". Quantity + free-text
 * description deliberately, not a rigid schema — location, brand, colour
 * and state annotations ("missing") all live in `description`. Attaches to
 * the property's own PropertyRoom (§0b, 2026-09-22) — the agent picks the
 * room CoreX already knows about, never retypes one. `room_label` stays for
 * back-compat display on any line captured before this change.
 */
class RentalInventoryLine extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'property_room_id',
        'room_label',
        'quantity',
        'description',
        'sort_order',
        'is_retired',
        'created_by_user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sort_order' => 'integer',
        'is_retired' => 'boolean',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(RentalInventory::class, 'rental_inventory_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(PropertyRoom::class, 'property_room_id');
    }

    /** §0b — Johan's own example: the TV's serial number, tagged to the photo it's visible in. Optional, never required. */
    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(RentalInventoryPhoto::class, 'rental_inventory_line_photos');
    }

    /** §8 — every move-out finding ever recorded against this line, oldest first. Never edited, never deleted. */
    public function dispositions(): HasMany
    {
        return $this->hasMany(RentalInventoryLineDisposition::class)->oldest('recorded_at')->oldest('id');
    }

    /**
     * §8 — the most recent finding is "current"; every earlier one stays in
     * the audit trail, unmodified. Queried fresh (not through dispositions()
     * above, which orders oldest-first for history display) to avoid
     * stacking a contradictory orderBy on top of that relation's own.
     */
    public function latestDisposition(): ?RentalInventoryLineDisposition
    {
        return RentalInventoryLineDisposition::where('rental_inventory_line_id', $this->id)
            ->latest('recorded_at')->latest('id')->first();
    }

    /**
     * §3.3-style retirement — never a hard delete. A line added in error
     * stays visible in history, marked retired, exactly like
     * RentalInspectionItem::retire()'s own reasoning.
     */
    public function retire(): void
    {
        $this->update(['is_retired' => true]);
    }
}
