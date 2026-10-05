<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-442 — an agency's own labour/part catalogue, consumed by internal job
 * cards (rental-work-orders.md §14). Full CRUD, per-agency, soft-delete
 * only. No seeded defaults — unlike RentalFaultType/AgencyServiceType, this
 * list is agency-specific pricing and naming from day one with nothing
 * sensible to seed.
 */
class RentalCatalogueItem extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const TYPE_LABOUR = 'labour';
    public const TYPE_PART = 'part';

    protected $fillable = [
        'agency_id',
        'type',
        'name',
        'unit',
        'default_price',
        'default_rental_vat_type_id',
        'is_active',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function defaultVatType(): BelongsTo
    {
        return $this->belongsTo(RentalVatType::class, 'default_rental_vat_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function archive(): void
    {
        $this->delete();
    }

    public function restoreRecord(): void
    {
        $this->restore();
    }
}
