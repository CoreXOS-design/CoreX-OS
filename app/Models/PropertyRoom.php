<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-inspections.md — Stage 2 of the inspections rework. The
 * neutral room concept a property carries independent of any one pillar:
 * Rentals' inspection facets (RentalInspectionItem, kind='space') and
 * Sales' future inventory both join against this table rather than either
 * owning it. Never reference a Lease, Inspection, or anything
 * tenancy-shaped from here — that's exactly the leak Johan ruled against.
 *
 * `is_retired`, not `deleted_at` — matches RentalInspectionItem §3.3: a
 * room any real inspection or inventory item has ever referenced must
 * never become invisible to history-reading queries.
 */
class PropertyRoom extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'property_id',
        'type',
        'label',
        'source',
        'sort_order',
        'is_retired',
        'created_by_user_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_retired' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function inspectionItems(): HasMany
    {
        return $this->hasMany(RentalInspectionItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
