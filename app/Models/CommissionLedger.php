<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class CommissionLedger extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'commission_ledger';

    protected $fillable = [
        'user_id',
        'agency_id',
        'cap_period_id',
        'deal_id',
        'property_id',
        'transaction_type',
        'description',
        'gross_commission',
        'vat_amount',
        'commission_excl_vat',
        'agent_split_percent',
        'agent_amount',
        'agency_amount',
        'transaction_fee',
        'risk_fee',
        'mentor_fee',
        'is_post_cap',
        'net_agent_amount',
        'company_dollar',
        'revenue_share_pool',
        'status',
        'deal_date',
        'paid_at',
    ];

    protected $casts = [
        'gross_commission' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'commission_excl_vat' => 'decimal:2',
        'agent_amount' => 'decimal:2',
        'agency_amount' => 'decimal:2',
        'transaction_fee' => 'decimal:2',
        'risk_fee' => 'decimal:2',
        'mentor_fee' => 'decimal:2',
        'is_post_cap' => 'boolean',
        'net_agent_amount' => 'decimal:2',
        'company_dollar' => 'decimal:2',
        'revenue_share_pool' => 'decimal:2',
        'deal_date' => 'date',
        'paid_at' => 'datetime',
    ];

    // ── Relationships ──

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function capPeriod()
    {
        return $this->belongsTo(AgentCapPeriod::class, 'cap_period_id');
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function revenueShareEntries()
    {
        return $this->hasMany(RevenueShareLedger::class, 'commission_ledger_id');
    }

    // ── Scopes ──

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('created_at', now()->year)
                     ->whereMonth('created_at', now()->month);
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('created_at', now()->year);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ── Methods ──

    /**
     * Calculate the commission split for a deal.
     * Returns the filled attributes (does not save).
     */
    public static function calculateSplit(array $params): array
    {
        $grossCommission = $params['gross_commission'];
        $agencyId = $params['agency_id'];
        $userId = $params['user_id'];

        $settings = CommissionSetting::forAgency($agencyId);
        $capPeriod = AgentCapPeriod::currentForUser($userId, $agencyId);

        // VAT calculation
        $vatRate = 0.15;
        $commissionExclVat = bcdiv($grossCommission, bcadd('1', (string) $vatRate, 4), 2);
        $vatAmount = bcsub($grossCommission, $commissionExclVat, 2);

        $transactionFee = '0.00';
        $riskFee = '0.00';
        $mentorFee = '0.00';
        $isPostCap = false;

        if ($capPeriod->checkCap()) {
            // POST-CAP: Agent gets commission minus fees
            $isPostCap = true;

            // Determine transaction fee (reduced if post-cap fee cap reached)
            if (bccomp($capPeriod->post_cap_fees_paid, $settings->post_cap_fee_cap, 2) >= 0) {
                $transactionFee = (string) $settings->post_cap_reduced_fee;
            } else {
                $transactionFee = (string) $settings->post_cap_transaction_fee;
            }

            // Risk fee (capped annually)
            if (bccomp($capPeriod->risk_fees_paid, $settings->risk_management_cap, 2) < 0) {
                $riskFee = (string) $settings->risk_management_fee;
            }

            $agentAmount = bcsub(bcsub($commissionExclVat, $transactionFee, 2), $riskFee, 2);
            $agencyAmount = bcadd($transactionFee, $riskFee, 2);
            $agentSplitPercent = 100;
        } else {
            // PRE-CAP: Standard split
            $agentSplitPercent = $settings->commission_split_agent;

            // Check mentor status
            $mentor = AgentMentor::where('mentee_user_id', $userId)->where('is_active', true)->first();
            if ($mentor && $capPeriod->transactions_mentored < $settings->mentor_transactions) {
                // Mentor fee applies on first N transactions
                $mentorFee = bcdiv(bcmul($commissionExclVat, (string) $settings->mentor_extra_split, 2), '100', 2);
            }

            // Risk fee (capped annually)
            if (bccomp($capPeriod->risk_fees_paid, $settings->risk_management_cap, 2) < 0) {
                $riskFee = (string) $settings->risk_management_fee;
            }

            $agentAmount = bcdiv(bcmul($commissionExclVat, (string) $agentSplitPercent, 2), '100', 2);
            $agencyAmount = bcsub($commissionExclVat, $agentAmount, 2);
            $agentAmount = bcsub(bcsub($agentAmount, $riskFee, 2), $mentorFee, 2);
        }

        // Company dollar = what the agency actually keeps
        $companyDollar = bcadd($agencyAmount, $mentorFee, 2);

        // Revenue share pool
        $revenueSharePool = '0.00';
        if ($settings->revenue_share_enabled) {
            $revenueSharePool = bcdiv(bcmul($companyDollar, (string) $settings->revenue_share_pool_percent, 2), '100', 2);
        }

        return [
            'gross_commission' => $grossCommission,
            'vat_amount' => $vatAmount,
            'commission_excl_vat' => $commissionExclVat,
            'agent_split_percent' => $agentSplitPercent,
            'agent_amount' => $agentAmount,
            'agency_amount' => $agencyAmount,
            'transaction_fee' => $transactionFee,
            'risk_fee' => $riskFee,
            'mentor_fee' => $mentorFee,
            'is_post_cap' => $isPostCap,
            'net_agent_amount' => $agentAmount,
            'company_dollar' => $companyDollar,
            'revenue_share_pool' => $revenueSharePool,
            'cap_period_id' => $capPeriod->id,
        ];
    }

    /**
     * Generate revenue share entries from this commission ledger entry.
     */
    public function generateRevenueShare(): void
    {
        if (bccomp($this->revenue_share_pool, '0', 2) <= 0) {
            return;
        }

        $settings = CommissionSetting::forAgency($this->agency_id);
        $tierConfig = $settings->tier_config;

        // Walk the sponsorship tree upward from the producing agent
        $sponsorChain = AgentSponsorship::getSponsorChain($this->user_id);
        $periodMonth = ($this->deal_date ?? $this->created_at)->startOfMonth()->toDateString();

        foreach ($sponsorChain as $sponsorUserId => $tier) {
            if (!isset($tierConfig[$tier])) {
                continue;
            }

            $tierPercent = $tierConfig[$tier]['percent'];
            $flqaRequired = $tierConfig[$tier]['flqa_required'] ?? 0;

            // Check FLQA requirement for tiers 4+
            if ($flqaRequired > 0) {
                $flqaCount = AgentSponsorship::getFLQACount($sponsorUserId);
                if ($flqaCount < $flqaRequired) {
                    continue;
                }
            }

            // Check receiving agent is active
            $receiver = User::where('id', $sponsorUserId)->where('is_active', true)->first();
            if (!$receiver) {
                continue;
            }

            $shareAmount = bcdiv(bcmul($this->company_dollar, (string) $tierPercent, 4), '100', 2);

            if (bccomp($shareAmount, '0', 2) <= 0) {
                continue;
            }

            RevenueShareLedger::create([
                'commission_ledger_id' => $this->id,
                'producing_agent_id' => $this->user_id,
                'receiving_agent_id' => $sponsorUserId,
                'tier' => $tier,
                'company_dollar' => $this->company_dollar,
                'share_percent' => $tierPercent,
                'share_amount' => $shareAmount,
                'period_month' => $periodMonth,
            ]);
        }
    }
}
