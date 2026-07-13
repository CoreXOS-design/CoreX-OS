<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToAgency;
class AgentCapPeriod extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'user_id',
        'agency_id',
        'period_start',
        'period_end',
        'cap_amount',
        'company_dollar_paid',
        'is_capped',
        'capped_at',
        'post_cap_fees_paid',
        'risk_fees_paid',
        'transactions_count',
        'transactions_mentored',
        'gross_commission_income',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'cap_amount' => 'decimal:2',
        'company_dollar_paid' => 'decimal:2',
        'is_capped' => 'boolean',
        'capped_at' => 'datetime',
        'post_cap_fees_paid' => 'decimal:2',
        'risk_fees_paid' => 'decimal:2',
        'gross_commission_income' => 'decimal:2',
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

    public function commissionEntries()
    {
        return $this->hasMany(CommissionLedger::class, 'cap_period_id');
    }

    // ── Scopes ──

    public function scopeCurrent($query)
    {
        $today = now()->toDateString();

        return $query->where('period_start', '<=', $today)
                     ->where('period_end', '>=', $today);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ── Methods ──

    /**
     * Record company dollar payment and check if agent has capped.
     */
    public function recordCompanyDollar(float $amount): void
    {
        $this->company_dollar_paid = bcadd($this->company_dollar_paid, $amount, 2);
        $this->transactions_count++;

        if (!$this->is_capped && bccomp($this->company_dollar_paid, $this->cap_amount, 2) >= 0) {
            $this->is_capped = true;
            $this->capped_at = now();
        }

        $this->save();
    }

    /**
     * Record a post-cap transaction fee.
     */
    public function recordPostCapFee(float $amount): void
    {
        $this->post_cap_fees_paid = bcadd($this->post_cap_fees_paid, $amount, 2);
        $this->save();
    }

    /**
     * Record a risk management fee.
     */
    public function recordRiskFee(float $amount): void
    {
        $this->risk_fees_paid = bcadd($this->risk_fees_paid, $amount, 2);
        $this->save();
    }

    /**
     * Record GCI (gross commission income).
     */
    public function recordGCI(float $amount): void
    {
        $this->gross_commission_income = bcadd($this->gross_commission_income, $amount, 2);
        $this->save();
    }

    /**
     * Check if agent has reached their cap.
     */
    public function checkCap(): bool
    {
        return $this->is_capped || bccomp((string) ($this->company_dollar_paid ?? '0'), (string) ($this->cap_amount ?? '0'), 2) >= 0;
    }

    /**
     * Get remaining amount to reach cap.
     */
    public function getRemainingToCap(): string
    {
        if ($this->is_capped) {
            return '0.00';
        }

        $remaining = bcsub($this->cap_amount, $this->company_dollar_paid, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }

    /**
     * Get cap progress as percentage (0-100).
     */
    public function getCapProgressPercent(): float
    {
        if (bccomp($this->cap_amount, '0', 2) === 0) {
            return 100.0;
        }

        $percent = (float) bcdiv(bcmul($this->company_dollar_paid, '100', 2), $this->cap_amount, 2);

        return min($percent, 100.0);
    }

    /**
     * Get or create the current cap period for a user.
     */
    public static function currentForUser(int $userId, int $agencyId): self
    {
        // AT-253 (STANDARDS Rule 17) — this CREATES a cap period stamped with $agencyId, and
        // agent_cap_periods.agency_id is NOT NULL. Callers used to pass `effectiveAgencyId() ?? 1`,
        // so an owner/super-admin viewing the commission screen silently opened a cap period
        // — a real financial record — inside AGENCY 1. Guard at the model so every caller is
        // safe, rather than trusting each one to remember.
        if ($agencyId <= 0) {
            throw new \App\Exceptions\MissingAgencyContextException('a commission cap period');
        }

        $today = now()->toDateString();

        $existing = static::forUser($userId)
            ->current()
            ->first();

        if ($existing) {
            return $existing;
        }

        // Determine anniversary date from user
        $user = User::find($userId);
        $anniversaryDate = $user?->anniversary_date ?? now()->toDateString();

        // Calculate current period based on anniversary
        $anniversary = \Carbon\Carbon::parse($anniversaryDate);
        $periodStart = $anniversary->copy();

        // Move to the correct anniversary year
        while ($periodStart->copy()->addYear()->lte(now())) {
            $periodStart->addYear();
        }
        while ($periodStart->gt(now())) {
            $periodStart->subYear();
        }

        $periodEnd = $periodStart->copy()->addYear()->subDay();

        // Get cap amount from agency settings
        $settings = CommissionSetting::forAgency($agencyId);

        return static::create([
            'user_id' => $userId,
            'agency_id' => $agencyId,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'cap_amount' => $settings->annual_cap,
        ]);
    }
}
