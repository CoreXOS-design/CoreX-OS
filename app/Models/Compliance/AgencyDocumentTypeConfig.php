<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgencyDocumentTypeConfig extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'agency_document_type_configs';

    protected $fillable = [
        'agency_id',
        'name',
        'slug',
        'description',
        'has_expiry',
        'renewal_days',
        'required',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'has_expiry'              => 'boolean',
        'renewal_days'            => 'integer',
        'required'                => 'boolean',
        'sort_order'              => 'integer',
        'is_active'               => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ── Relationships (forward ref for Phase 2) ──

    public function provisions(): HasMany
    {
        return $this->hasMany(AgencyComplianceProvision::class, 'document_type_config_id');
    }
}
