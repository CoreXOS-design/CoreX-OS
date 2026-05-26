<?php

namespace App\Models\CommandCenter;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgencyFeedbackOption extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id', 'category', 'label', 'description',
        'is_active', 'sort_order', 'is_system_default',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'is_system_default' => 'boolean',
        'sort_order'        => 'integer',
    ];

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Return system defaults + agency-specific options for a category.
     *
     * Bypasses AgencyScope because we need to UNION shared rows
     * (agency_id IS NULL system defaults) with the tenant's own rows.
     * The strict AgencyScope (see .ai/specs/multi-tenancy.md §2a) would
     * otherwise filter NULL rows out, returning only per-agency overrides
     * and hiding the system defaults that this scope exists to surface.
     */
    public function scopeAvailableForAgency(Builder $query, ?int $agencyId): Builder
    {
        return $query->withoutGlobalScope(AgencyScope::class)
            ->where('is_active', true)
            ->where(function ($q) use ($agencyId) {
                $q->whereNull('agency_id');
                if ($agencyId) {
                    $q->orWhere('agency_id', $agencyId);
                }
            })
            ->orderBy('sort_order');
    }
}
