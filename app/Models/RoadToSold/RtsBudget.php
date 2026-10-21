<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agency Tracker re-home step 4 - the Road to Sold home of budgets (agency-tracker-rehome.md 4.3).
 *
 * One row per (agency, track, who, month) at three levels:
 *   agent  - personal + business budget and the planning figures behind "what the budget needs"
 *   branch - what the branch needs for the month
 *   agency - what the whole agency needs for the month
 *
 * Written ONLY through App\Services\RoadToSold\BudgetService (it owns the restore-never-duplicate rule and
 * the who-may-set-whose rule). Soft delete only.
 */
class RtsBudget extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const LEVEL_AGENT  = 'agent';
    public const LEVEL_BRANCH = 'branch';
    public const LEVEL_AGENCY = 'agency';

    /** The planning figures an agent row may carry (empty = not set). */
    public const PLANNING = ['avg_sale_price', 'commission_percent', 'paye_percent', 'agent_split_percent', 'correctly_priced_percent'];

    /** The largest money figure a screen accepts (the column is decimal(12,2)). */
    public const MAX_MONEY = 9999999999.99;

    protected $table = 'rts_budgets';

    protected $fillable = [
        'agency_id', 'branch_id', 'user_id', 'track', 'level', 'scope_key', 'period',
        'personal_budget', 'business_budget', 'requirement',
        'avg_sale_price', 'commission_percent', 'paye_percent', 'agent_split_percent', 'correctly_priced_percent',
        'set_by_user_id', 'legacy_source', 'legacy_id',
    ];

    protected $casts = [
        'personal_budget'          => 'decimal:2',
        'business_budget'          => 'decimal:2',
        'requirement'              => 'decimal:2',
        'avg_sale_price'           => 'decimal:2',
        'commission_percent'       => 'decimal:2',
        'paye_percent'             => 'decimal:2',
        'agent_split_percent'      => 'decimal:2',
        'correctly_priced_percent' => 'decimal:2',
    ];

    public static function keyForUser(int $userId): string
    {
        return 'user:' . $userId;
    }

    public static function keyForBranch(int $branchId): string
    {
        return 'branch:' . $branchId;
    }

    public const KEY_AGENCY = 'agency';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
