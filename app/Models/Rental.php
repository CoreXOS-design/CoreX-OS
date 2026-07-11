<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;

class Rental extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $table = 'rentals';

    protected $fillable = [
        'branch_id',
        'lease_address',
        'lease_start_date',
        'lease_end_date',
        'is_month_to_month',
        'is_active',
        'is_rental_assist',
        'created_by_user_id',
    ];

    protected $casts = [
        'lease_start_date' => 'date',
        'lease_end_date' => 'date',
        'is_month_to_month' => 'boolean',
        'is_active' => 'boolean',
        'is_rental_assist' => 'boolean',
    ];

    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'rentals');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        if ($scope === 'own') {
            return $query->whereHas('agents', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function amountVersions(): HasMany
    {
        return $this->hasMany(RentalAmountVersion::class, 'rental_id')
            ->orderBy('effective_from', 'desc');
    }

    public function currentAmountVersion()
    {
        return $this->hasOne(RentalAmountVersion::class, 'rental_id')
            ->orderBy('effective_from', 'desc');
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'rental_agents')
            ->withTimestamps();
    }

    /**
     * Get the commission_excl applicable for a given date.
     */
    public function getCommissionExclForDate($date)
    {
        $version = $this->amountVersions()
            ->where('effective_from', '<=', $date)
            ->orderBy('effective_from', 'desc')
            ->first();

        return $version ? $version->commission_excl : 0;
    }
}
