<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
class Worksheet extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, SoftDeletes;

    /** A worksheet's branch is its owning agent's — never the acting user's. */
    protected function branchParent(): array
    {
        return [\App\Models\User::class, 'user_id'];
    }

    protected $fillable = [
        'agency_id',
        'user_id',
        'period',

        'personal_net_target',
        'business_net_target',
        'want_net_target',

        'avg_sale_price',
        'avg_sale_price_admin',
        'commission_percent',
        'commission_percent_admin',
        'commission_percent_locked',
        'paye_percent',

        'agent_split_percent',
        'correctly_priced_percent',

        'current_listings',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
