<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-agency feature override (spec: corex-feature-registry.md §4.1).
 *
 * Stores only deviations from the registry default. Resolution ("no row =>
 * registry default", core-always-on, depends_on cascade, env-flag AND) lives in
 * App\Services\Features\AgencyFeatureService — this model is just the store.
 *
 * Multi-tenancy: BelongsToAgency auto-fills agency_id from the effective agency
 * and registers AgencyScope, so an agency can never read/write another's rows.
 */
class AgencyFeature extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'agency_features';

    protected $fillable = [
        'agency_id',
        'feature_key',
        'enabled',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
