<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inventory.md §3.1 — one real item: "2x White wooden
 * headboards", "4x Remotes - 2x Fans - 2x Aircon". Quantity + free-text
 * description deliberately, not a rigid schema — location, brand, colour
 * and state annotations ("missing") all live in `description`.
 */
class RentalInventoryLine extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
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
