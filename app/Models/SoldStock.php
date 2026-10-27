<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Frozen advert + sale facts of one completed sale (one row per property per listing cycle).
 * Read-only to users, soft delete only — .ai/specs/sold-stock.md.
 */
class SoldStock extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'sold_stock';

    protected $guarded = ['id'];

    protected $casts = [
        'features'           => 'array',
        'agent_ids'          => 'array',
        'seller_contact_ids' => 'array',
        'buyer_contact_ids'  => 'array',
        'photos'             => 'array',
        'asking_price'       => 'decimal:2',
        'sale_price'         => 'decimal:2',
        'sale_date'          => 'date',
        'listed_date'        => 'date',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function secondAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_agent_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** Own / branch / agency — the Properties data scope, enforced at the query layer. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->where('sold_stock.branch_id', $user->effectiveBranchId());
        }
        if ($scope === 'own') {
            $ids = $user->dataIdentityIds();

            return $query->where(fn ($q) => $q->whereIn('sold_stock.agent_id', $ids)
                ->orWhereIn('sold_stock.second_agent_id', $ids));
        }

        return $query->whereRaw('1 = 0');
    }
}
