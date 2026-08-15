<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToAgency;

/**
 * 2026-08-15 (Johan, HFC tenant-isolation fix, Wave 2, #8) — added
 * agency_id + BelongsToAgency. Was single-tenant from day one (no
 * agency_id, no user_id, no branch_id at all) — every agency with
 * access_trust_interest saw and could edit/delete the same shared
 * register. Route-model binding in the controller (update/destroy) is now
 * safe "for free" via the global scope, same as every other
 * BelongsToAgency model.
 */
class DepositTrustInterest extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'deposit_trust_interest';

    protected $fillable = [
        'agency_id',
        'interest_date',
        'total_invested_funds',
        'interest_earned',
    ];

    protected $casts = [
        'interest_date' => 'date',
        'total_invested_funds' => 'decimal:2',
        'interest_earned' => 'decimal:2',
    ];

    public function scopeDefaultOrder($query)
    {
        return $query->orderBy('interest_date', 'desc');
    }
}
