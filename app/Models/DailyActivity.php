<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
class DailyActivity extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, SoftDeletes;

    /** A daily activity's branch is its owning agent's. */
    protected function branchParent(): array
    {
        return [\App\Models\User::class, 'user_id'];
    }

    protected $fillable = [
        'agency_id',
        'activity_date',
        'period',
        'user_id',
        'branch_id',
        'calls_made',
        'doors_knocked',
        'whatsapps_sent',
        'referrals_asked',
        'flyers_dropped',
        'presentations_booked',
        'presentations_done',
        'oats_signed',
        'eats_signed',
        'buyer_leads',
        'seller_leads',
        'portal_leads',
        'referral_leads',
        'buyer_appointments',
        'otps_written',
        'otps_accepted',
        'otps_collapsed',
        'prospecting',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'activity_date' => 'date',
    ];

    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'daily_activity');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        if ($scope === 'own') return $query->where('user_id', $user->id);

        return $query->whereRaw('1 = 0');
    }
}
