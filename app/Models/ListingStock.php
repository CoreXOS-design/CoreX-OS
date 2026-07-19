<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
class ListingStock extends Model
{
    use BelongsToBranch, BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'user_id',
        'branch_id',
        'source',
        'external_id',
        'external_ref',
        'property',
        'status',
        'price_cents',
        'cma_price_cents',
        'cma_updated_at',
        'category',
        'type',
        'region',
        'mandate',
        'listed_at',
        'modified_at',
        'expires_at',
        'raw_payload',
    ];

    protected $casts = [
        'branch_id'    => 'integer',
        'price_cents'  => 'integer',
        'listed_at'    => 'datetime',
        'modified_at'  => 'datetime',
        'expires_at'   => 'datetime',
        'raw_payload'  => 'array',
    ];

    // -----------------------------
    // Computed Listing Metrics
    // -----------------------------
    public function getDaysOnMarketAttribute(): ?int
    {
        $start = $this->listed_at ?? $this->created_at;
        if (!$start) return null;

                $d = $start->startOfDay()->diffInDays(now()->startOfDay(), false);
return $d < 0 ? 0 : $d;
    }

    public function getDaysSinceEditAttribute(): ?int
    {
        $last = $this->modified_at ?? $this->created_at;
        if (!$last) return null;

                $d = $last->startOfDay()->diffInDays(now()->startOfDay(), false);
return $d < 0 ? 0 : $d;
    }

    public function getDaysToExpiryAttribute(): ?int
    {
        if (!$this->expires_at) return null;

        // Negative means already expired
        return now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    public function getIsExpiredAttribute(): bool
    {
        if (!$this->expires_at) return false;

        return $this->expires_at->startOfDay()->lt(now()->startOfDay());
    }

    public function getExpiresOnAttribute(): ?string
    {
        return $this->expires_at ? $this->expires_at->toDateString() : null;
    }

    public function getIsStaleAttribute(): bool
    {
        $days = $this->days_since_edit;
        return $days !== null && $days >= 14;
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        $days = $this->days_to_expiry;
        return $days !== null && $days >= 0 && $days <= 14;
    }


    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'listings');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        // AT-267 — an assistant's 'own' is their Assigned Agent's; everyone else: [$user->id].
        if ($scope === 'own') return $query->whereIn('user_id', $user->dataIdentityIds());

        return $query->whereRaw('1 = 0');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'listing_stock_agents', 'listing_stock_id', 'user_id')
            ->withTimestamps();
    }
}
