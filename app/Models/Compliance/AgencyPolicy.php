<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AgencyPolicy (AT-29) — the registry row that makes staff policy sign-off
 * a generic framework. One per governing document per agency, keyed by a
 * stable `policy_key`. The Communication & Marketing Compliance Policy is
 * instance #1 (policy_key = 'communication_marketing').
 */
class AgencyPolicy extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'policy_key',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ──

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class, 'policy_id');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(PolicyVersion::class, 'policy_id')->where('status', 'active');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class, 'policy_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Methods ──

    public function currentVersion(): ?PolicyVersion
    {
        return $this->versions()->where('status', 'active')->first();
    }
}
