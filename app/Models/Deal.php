<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Scopes\DealBranchScope;
use App\Services\Finance\CommissionCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected static function booted(): void
    {
        // Pivot-based branch isolation — see spec §5 + §11
        static::addGlobalScope(new DealBranchScope());

        // Mirror deals.branch_id into the deal_branches pivot as the
        // originator row when a deal is created. If a deal is created
        // without branch_id (legacy), the pivot stays empty and the
        // deal is only visible to `branches.view_all` holders.
        static::created(function (Deal $deal) {
            if ($deal->branch_id && !$deal->branches()->where('branches.id', $deal->branch_id)->exists()) {
                $deal->branches()->attach($deal->branch_id, ['role' => 'originator']);
            }
        });

        // If the branch_id on the deal changes, keep the originator
        // row in sync. Co-branches are managed explicitly via
        // attachCoBranch()/detachCoBranch() and are left alone here.
        static::updated(function (Deal $deal) {
            if (!$deal->wasChanged('branch_id') || !$deal->branch_id) {
                return;
            }
            $deal->branches()->newPivotStatement()
                ->where('deal_id', $deal->id)
                ->where('role', 'originator')
                ->delete();
            $deal->branches()->attach($deal->branch_id, ['role' => 'originator']);
        });
    }

    /**
     * All branches attached to this deal (originator + co-branches).
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'deal_branches')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Attach a co-branch to this deal. No-op if already attached.
     */
    public function attachCoBranch(int $branchId): void
    {
        if ($this->branches()->where('branches.id', $branchId)->exists()) {
            return;
        }
        $this->branches()->attach($branchId, ['role' => 'co_branch']);
    }

    /**
     * Detach a co-branch. The originator row is protected — use
     * reassignOriginator() to move the mandate-holding branch.
     */
    public function detachCoBranch(int $branchId): void
    {
        $this->branches()->newPivotStatement()
            ->where('deal_id', $this->id)
            ->where('branch_id', $branchId)
            ->where('role', 'co_branch')
            ->delete();
    }

    protected $fillable = [
        'agency_id',
        'deal_no',

        'period',
        'deal_date',

        'property_value',
        'total_commission',

        'file_no',
        'branch_id',
        // Admin Multi-Branch Manager — the manager named on this deal,
        // captured at registration when an admin acts as the branch's
        // manager. See branchManager() + spec admin-multi-branch-manager.md.
        'managed_by_user_id',
        'property_address',
        'seller_name',
        'buyer_name',
        'attorney_name',
        'accepted_status',
        'granted_at',
        'commission_status',
        'registration_date',
        'remarks',

        'listing_external',
        'listing_split_percent',
        'listing_our_share_percent',
        'listing_external_agency',

        'selling_external',
        'selling_split_percent',
        'selling_our_share_percent',
        'selling_external_agency',

        'is_demo',

        // Phase 3i — property/presentation link + canonical sale fields.
        'property_id',
        'presentation_id',
        'sale_price',
        'sale_date',
        'link_source',
        'link_confidence',
        'link_reviewed_at',
        'link_reviewed_by_user_id',
    ];

    protected $casts = [
        'deal_date' => 'date',
        'registration_date' => 'date',
        'granted_at' => 'datetime',
        'property_value' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'listing_external' => 'boolean',
        'selling_external' => 'boolean',
        'listing_our_share_percent' => 'decimal:2',
        'selling_our_share_percent' => 'decimal:2',
          'listing_split_percent' => 'decimal:2',
          'selling_split_percent' => 'decimal:2',
        // Phase 3i.
        'sale_price'        => 'integer',
        'sale_date'         => 'date',
        'link_reviewed_at'  => 'datetime',
    ];

    /** Phase 3i — direct FK to the property this deal concerns. */
    public function property()
    {
        return $this->belongsTo(\App\Models\Property::class, 'property_id');
    }

    /** Phase 3i — the presentation that led to this deal, when known. */
    public function presentation()
    {
        return $this->belongsTo(\App\Models\Presentation::class, 'presentation_id');
    }

    /** The user explicitly recorded as the branch manager for this deal. */
    public function managedBy()
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    /**
     * The branch manager named on this deal.
     *
     * Resolution order (spec admin-multi-branch-manager.md §6.1):
     *   1. managed_by_user_id — the manager captured at registration
     *      (e.g. an admin acting as this branch's manager).
     *   2. Fallback — the first branch_manager-role user assigned to the
     *      deal's branch (today's role-based behaviour, unchanged for every
     *      deal that has no captured manager).
     *
     * The fallback query bypasses BranchScope so the manager resolves
     * regardless of the viewing user's own branch context; AgencyScope still
     * applies (the manager is always in the deal's agency).
     */
    public function branchManager(): ?User
    {
        if ($this->managed_by_user_id) {
            $captured = $this->managedBy;
            if ($captured) {
                return $captured;
            }
        }

        if (!$this->branch_id) {
            return null;
        }

        return User::queryWithoutBranchScope()
            ->where('role', 'branch_manager')
            ->where('branch_id', $this->branch_id)
            ->orderBy('id')
            ->first();
    }

    public function agents()
    {
        return $this->belongsToMany(User::class)
            ->withPivot([
                'side',
                'agent_split_percent',
                'agent_cut_percent',
                'paye_method',
                'paye_value',
                'deductions',
                'deductions_description',
                'paid_at',

                // Sliding audit (nullable)
                'sliding_granted_month',
                'sliding_sequence_in_month',
                'sliding_applied_cut_percent',
                'sliding_applied_at',
            ])
            ->withTimestamps();
    }

    public function listingAgents()
    {
        return $this->agents()->wherePivot('side', 'listing');
    }

    public function sellingAgents()
    {
        return $this->agents()->wherePivot('side', 'selling');
    }

    

    /**
     * Internal finance rule:
     * - total_commission is captured INCL VAT (bank reality)
     * - all internal pools/allocations are calculated EX VAT
     */
    public function commissionExVat(): float
    {
        $vatRatePercent = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);
        $vatRate = $vatRatePercent / 100.0;

        $inc = (float) $this->total_commission;
        if ($inc <= 0) return 0.0;
        if ($vatRate <= 0) return $inc;

        return $inc / (1.0 + $vatRate);
    }

    private function calculateInternalPool(string $side): float
    {
        $side = strtolower(trim($side));
        if (!in_array($side, ['listing', 'selling'], true)) return 0.0;

        $externalFlag = (bool) ($this->{$side . '_external'} ?? false);
        if ($externalFlag) return 0.0;

        $sidePct = (float) ($this->{$side . '_split_percent'} ?? 50);
        $ourPct  = (float) ($this->{$side . '_our_share_percent'} ?? 100);

        $sideFactor = max(0.0, min(1.0, $sidePct / 100.0));
        $ourFactor  = max(0.0, min(1.0, $ourPct / 100.0));

        return $this->commissionExVat() * $sideFactor * $ourFactor;
    }
public function listingPool()
    {
        return $this->calculateInternalPool('listing');
    }

    public function sellingPool()
    {
        return $this->calculateInternalPool('selling');
    }

    public function totalOurCommission()
    {
        return $this->listingPool() + $this->sellingPool();
    }

    /**
     * Branch-specific commission: sum of allocations for agents belonging to the given branch.
     * Uses the canonical allocations() engine (respects settlements, external sides, our-share %).
     */
    public function branchCommission(int $branchId): float
    {
        $allocations = $this->allocations();
        $total = 0.0;
        $counted = [];

        foreach ($this->agents as $agent) {
            if ((int) $agent->branch_id === $branchId && !in_array($agent->id, $counted)) {
                $total += (float) ($allocations[$agent->id] ?? 0);
                $counted[] = $agent->id;
            }
        }

        return $total;
    }

    /**
     * Deal Register allocations:
     * - If settlement overrides exist (deal_settlements) for a side, use them (actual paid reality).
     * - Otherwise fall back to pivot agent_split_percent.
     */
    public function allocations()
    {
        $result = [];

        $this->allocateSide($result, 'listing', $this->listingAgents()->get(), (float)$this->listingPool());
        $this->allocateSide($result, 'selling', $this->sellingAgents()->get(), (float)$this->sellingPool());

        return $result;
    }

    private function allocateSide(array &$result, string $side, $agents, float $pool): void
    {
        if ($agents->count() === 0 || $pool <= 0) return;

        $allocatedSum = 0.0;

        // If settlement rows exist for this deal+side, they are the truth.
        $settlements = DealSettlement::where('deal_id', $this->id)
            ->where('side', $side)
            ->get();

        if ($settlements->count() > 0) {
            foreach ($settlements as $s) {
                $userId = (int)$s->user_id;
                $percent = (float)$s->share_percent;
                $amount = $pool * ($percent / 100);
                $result[$userId] = ($result[$userId] ?? 0) + $amount;
                $allocatedSum += (float)$amount;
            }
            return;
        }

        // Otherwise fall back to pivot splits
        $overridden = [];
        $normal = [];
        $overrideTotal = 0.0;

        foreach ($agents as $agent) {
            if ($agent->pivot->agent_split_percent !== null && $agent->pivot->agent_split_percent !== '') {
                $percent = (float)$agent->pivot->agent_split_percent;
                $overridden[] = [$agent, $percent];
                $overrideTotal += $percent;
            } else {
                $normal[] = $agent;
            }
        }

        if ($overrideTotal > 100) $overrideTotal = 100;

        foreach ($overridden as [$agent, $percent]) {
            $amount = $pool * ($percent / 100);
            $result[$agent->id] = ($result[$agent->id] ?? 0) + $amount;
            $allocatedSum += (float)$amount;
        }

        $remaining = $pool * ((100 - $overrideTotal) / 100);

        if (count($normal) > 0) {
            $each = $remaining / count($normal);
            foreach ($normal as $agent) {
                $result[$agent->id] = ($result[$agent->id] ?? 0) + $each;
                $allocatedSum += (float)$each;
            }
        }

        // Reconcile: if agent shares do not total 100 (legacy data), the remainder is company/unallocated.
        // This ensures Deal Register (Company + agents) always matches the pool.
        $remainder = $pool - $allocatedSum;
        if ($remainder > 0.009) { // > 1c tolerance
            $result[0] = ($result[0] ?? 0) + $remainder; // 0 = Company bucket
        }
    }


    // Status summary for dashboards (BM / TV)
        // Status summary for dashboards (BM / TV)
    
                public static function statusSummaryForBranch(int $branchId, string $period): array
    {
        $start = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // DISTINCT deal IDs touching this branch via agents in the period
        $dealIds = \DB::table('deal_user')
            ->join('users', 'users.id', '=', 'deal_user.user_id')
            ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
            ->where('users.branch_id', $branchId)
            ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
            ->distinct()
            ->pluck('deals.id');

        $declined = 0;
        $pending = 0;
        $granted = 0;
        $registered = 0;

        // Unpaid (main numbers)
        $pendingUnpaid = 0.0;
        $grantedUnpaid = 0.0;
        $registeredUnpaid = 0.0;

        // Paid (period) – so money doesn’t “disappear” when status flips to Paid
        $grantedPaidPeriod = 0.0;
        $registeredPaidPeriod = 0.0;

        foreach ($dealIds as $dealId) {

            /** @var \App\Models\Deal|null $deal */
            $deal = self::with('agents')->find($dealId);
            if (!$deal) continue;

            // Canonical split engine (respects external sides, our-share %, settlement overrides, etc.)
            $allocations = $deal->allocations(); // [user_id => amount_ex_vat], plus optional [0 => company remainder]

            // Branch share = sum of allocated amounts for agents in this branch
            $branchShare = 0.0;
            $counted = [];
            foreach ($deal->agents as $agent) {
                if ((int) $agent->branch_id === $branchId && !in_array($agent->id, $counted)) {
                    $branchShare += (float) ($allocations[$agent->id] ?? 0);
                    $counted[] = $agent->id;
                }
            }

            // If this branch has no share on this deal, it should not count or add money.
            if ($branchShare <= 0) continue;

            // Status logic per DEAL (counts must be DISTINCT deal_id per branch)
            $isDeclined   = ($deal->accepted_status === 'D');
            $isRegistered = !empty($deal->registration_date) || $deal->accepted_status === 'R';
            $isGranted    = !$isRegistered && (!empty($deal->granted_at) || $deal->accepted_status === 'G') && !$isDeclined;
            $isPending    = !$isRegistered && !$isGranted && !$isDeclined;

            $isPaid = (string)($deal->commission_status ?? '') === 'Paid';

            if ($isDeclined) {
                $declined++;
                continue;
            }

            if ($isRegistered) {
                $registered++;

                if ($isPaid) {
                    $registeredPaidPeriod += $branchShare;
                } else {
                    $registeredUnpaid += $branchShare;
                }
                continue;
            }

            if ($isGranted) {
                $granted++;

                if ($isPaid) {
                    $grantedPaidPeriod += $branchShare;
                } else {
                    $grantedUnpaid += $branchShare;
                }
                continue;
            }

            if ($isPending) {
                $pending++;
                // Pending cannot be paid (business rule), so always unpaid
                $pendingUnpaid += $branchShare;
            }
        }

        return [
            'declined_period'   => $declined,
            'pending_period'    => $pending,
            'granted_period'    => $granted,
            'registered_period' => $registered,

            // Main tile money (NOT PAID)
            'pending_unpaid_company_ex_vat'    => round($pendingUnpaid, 2),
            'granted_unpaid_company_ex_vat'    => round($grantedUnpaid, 2),
            'registered_unpaid_company_ex_vat' => round($registeredUnpaid, 2),

            // Secondary lines (PAID within the selected period)
            'granted_paid_company_ex_vat_period'    => round($grantedPaidPeriod, 2),
            'registered_paid_company_ex_vat_period' => round($registeredPaidPeriod, 2),

            // Legacy keys still used by some tiles
            'pending_total'     => $pending,
            'granted_total'     => $granted,

            'declined'          => $declined,
            'pending'           => $pending,
            'granted'           => $granted,
            'registered'        => $registered,
        ];
    }

    /**
     * Market averages for BM budgeting / planning (branch + period + chosen statuses).
     * Uses canonical status derivation:
     * - Registered  => registration_date is set
     * - Granted     => granted_at is set AND registration_date empty AND not declined
     * - Pending     => granted_at empty AND registration_date empty AND not declined
     *
     * Returns averages based on deals.property_value and deals.total_commission (captured INCL VAT),
     * plus effective commission % computed on EX VAT basis for consistency.
     */
    public static function marketAveragesForBranch(int $branchId, string $period, array $includeStages, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $incPending     = (bool)($includeStages['pending'] ?? true);
        $incGranted     = (bool)($includeStages['granted'] ?? true);
        $incRegistered  = (bool)($includeStages['registered'] ?? true);

        // If none selected, return zeroed stats
        if (!$incPending && !$incGranted && !$incRegistered) {
            return [
                'deals_count' => 0,
                'avg_sale_price_inc_vat' => 0.0,
                'avg_sale_price_ex_vat'  => 0.0,
                'effective_commission_percent_ex_vat' => 0.0,
                'sales_value_inc_vat' => 0.0,
                'sales_value_ex_vat'  => 0.0,
                'total_commission_inc_vat' => 0.0,
                'total_commission_ex_vat'  => 0.0,
            ];
        }

        $vatRatePercent = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);
        $vatRate = $vatRatePercent / 100.0;
        $vatDiv = 1.0 + $vatRate;

        // Use agent branch membership (same logic as statusSummaryForBranch)
        // so cross-branch deals where this branch's agents participate are included.
        $q = self::query()
              ->whereIn('id', function ($sub) use ($branchId) {
                  $sub->select('deal_user.deal_id')
                      ->from('deal_user')
                      ->join('users', 'users.id', '=', 'deal_user.user_id')
                      ->where('users.branch_id', $branchId)
                      ->distinct();
              });

          // Period filter only applies when we are doing a single-month view.
          // For rolling windows (3m/6m) we pass dateFrom/dateTo and MUST NOT lock to a single period.
          // For "all time" we pass period="all" and no date window.
          if ($period !== 'all' && !$dateFrom && !$dateTo) {
              $q->where('period', $period);
          }
// Optional date window filter (uses deal_date)
        if ($dateFrom) {
            $q->whereDate('deal_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $q->whereDate('deal_date', '<=', $dateTo);
        }

        // Apply chosen stages (OR group)
        $q->where(function ($qq) use ($incPending, $incGranted, $incRegistered) {

            // Registered
            if ($incRegistered) {
                $qq->orWhereNotNull('registration_date');
            }

            // Granted (not declined) — honour both granted_at date and accepted_status = 'G'
            if ($incGranted) {
                $qq->orWhere(function ($g) {
                    $g->whereNull('registration_date')
                      ->where('accepted_status', '!=', 'R')
                      ->where(function ($gInner) {
                          $gInner->whereNotNull('granted_at')
                                 ->orWhere('accepted_status', 'G');
                      })
                      ->where(function ($s) {
                          $s->whereNull('accepted_status')
                            ->orWhere('accepted_status', '!=', 'D');
                      });
                });
            }

            // Pending (not declined, not granted, not registered)
            if ($incPending) {
                $qq->orWhere(function ($p) {
                    $p->whereNull('registration_date')
                      ->where(function ($s) {
                          $s->whereNull('accepted_status')
                            ->orWhereNotIn('accepted_status', ['D', 'G', 'R']);
                      })
                      ->whereNull('granted_at');
                });
            }
        });

        $deals = $q->get(['property_value','total_commission']);

        $salesInc = 0.0;
        $salesEx  = 0.0;
        $commInc  = 0.0;
        $commEx   = 0.0;

        foreach ($deals as $d) {
            $pvInc = (float)($d->property_value ?? 0);
            $pvEx  = $pvInc; // Sale price is not VAT-rated

            $cInc = (float)($d->total_commission ?? 0);
            $cEx  = ($vatDiv > 0) ? ($cInc / $vatDiv) : 0.0;

            $salesInc += $pvInc;
            $salesEx  += $pvEx;
            $commInc  += $cInc;
            $commEx   += $cEx;
        }

        $count = (int) $deals->count();
        $avgSaleInc = ($count > 0) ? ($salesInc / $count) : 0.0;
        $avgSaleEx  = ($count > 0) ? ($salesEx  / $count) : 0.0;

        $effectiveCommPctEx = ($salesInc > 0) ? (($commEx / $salesInc) * 100.0) : 0.0;

        return [
            'deals_count' => $count,

            'sales_value_inc_vat' => $salesInc,
            'sales_value_ex_vat'  => $salesEx,

            'total_commission_inc_vat' => $commInc,
            'total_commission_ex_vat'  => $commEx,

            'avg_sale_price_inc_vat' => $avgSaleInc,
            'avg_sale_price_ex_vat'  => $avgSaleEx,

            'effective_commission_percent_ex_vat' => $effectiveCommPctEx,
        ];
    }


            public static function statusSummaryForCompany(string $period): array
    {
        $start = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // Deals in this period
        $dealIds = self::query()
            ->whereBetween('deal_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('id');

        $declined = 0;
        $pending = 0;
        $granted = 0;
        $registered = 0;

        // Unpaid (main numbers)
        $pendingUnpaid = 0.0;
        $grantedUnpaid = 0.0;
        $registeredUnpaid = 0.0;

        // Paid (period) – so money doesn’t “disappear”
        $grantedPaidPeriod = 0.0;
        $registeredPaidPeriod = 0.0;

        foreach ($dealIds as $dealId) {

            /** @var \App\Models\Deal|null $deal */
            $deal = self::with('agents')->find($dealId);
            if (!$deal) continue;

            // Canonical split engine — sum of allocations = true company ex VAT pool
            $allocations = $deal->allocations();
            $companyExVat = 0.0;
            foreach ($allocations as $uid => $amt) {
                $companyExVat += (float)$amt; // include remainder bucket (0) too
            }

            $isDeclined   = ($deal->accepted_status === 'D');
            $isRegistered = !empty($deal->registration_date) || $deal->accepted_status === 'R';
            $isGranted    = !$isRegistered && (!empty($deal->granted_at) || $deal->accepted_status === 'G') && !$isDeclined;
            $isPending    = !$isRegistered && !$isGranted && !$isDeclined;
            $isPaid       = (string)($deal->commission_status ?? '') === 'Paid';

            if ($isDeclined) {
                $declined++;
                continue;
            }

            if ($isRegistered) {
                $registered++;
                if ($isPaid) $registeredPaidPeriod += $companyExVat;
                else         $registeredUnpaid     += $companyExVat;
                continue;
            }

            if ($isGranted) {
                $granted++;
                if ($isPaid) $grantedPaidPeriod += $companyExVat;
                else         $grantedUnpaid     += $companyExVat;
                continue;
            }

            if ($isPending) {
                $pending++;
                $pendingUnpaid += $companyExVat;
            }
        }

        return [
            'declined_period'   => $declined,
            'pending_period'    => $pending,
            'granted_period'    => $granted,
            'registered_period' => $registered,

            // NOT PAID (main tile money)
            'pending_unpaid_company_ex_vat'    => round($pendingUnpaid, 2),
            'granted_unpaid_company_ex_vat'    => round($grantedUnpaid, 2),
            'registered_unpaid_company_ex_vat' => round($registeredUnpaid, 2),

            // PAID (this period)
            'granted_paid_company_ex_vat_period'    => round($grantedPaidPeriod, 2),
            'registered_paid_company_ex_vat_period' => round($registeredPaidPeriod, 2),

            // Keep existing keys used elsewhere
            'pending_total'     => $pending,
            'granted_total'     => $granted,

            'declined'          => $declined,
            'pending'           => $pending,
            'granted'           => $granted,
            'registered'        => $registered,
        ];
    }




    /**
     * Canonical deal visibility rule.
     * - Admin: sees all deals (global), regardless of branch_id.
     * - Branch Manager: sees deals in their branch.
     * - Agent: sees only deals linked to them in deal_user.
     */
    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'deals');

        if ($scope === 'all') return $query;

        if ($scope === 'branch') {
            return $query->whereIn('id', function ($sub) use ($user) {
                $sub->select('deal_user.deal_id')
                    ->from('deal_user')
                    ->join('users', 'users.id', '=', 'deal_user.user_id')
                    ->where('users.branch_id', $user->effectiveBranchId())
                    ->distinct();
            });
        }

        if ($scope === 'own') {
            return $query->whereIn('id', function ($sub) use ($user) {
                $sub->select('deal_id')
                    ->from('deal_user')
                    ->where('user_id', $user->id);
            });
        }

        return $query->whereRaw('1 = 0');
    }


}
