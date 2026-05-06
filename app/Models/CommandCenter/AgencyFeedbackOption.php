<?php

namespace App\Models\CommandCenter;

use App\Models\Concerns\BelongsToAgency;
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
     */
    public function scopeAvailableForAgency(Builder $query, ?int $agencyId): Builder
    {
        return $query->where('is_active', true)
            ->where(function ($q) use ($agencyId) {
                $q->whereNull('agency_id');
                if ($agencyId) {
                    $q->orWhere('agency_id', $agencyId);
                }
            })
            ->orderBy('sort_order');
    }
}
