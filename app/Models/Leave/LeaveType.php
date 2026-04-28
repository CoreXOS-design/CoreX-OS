<?php

namespace App\Models\Leave;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id', 'code', 'label', 'description', 'category',
        'is_paid', 'is_uif_claimable', 'requires_documentation',
        'documentation_label', 'documentation_threshold_days',
        'entitlement_days_per_cycle', 'entitlement_days_per_cycle_six_day',
        'cycle_months', 'accrual_method', 'accrual_rate_per_days',
        'accrual_starts_at_employment_date', 'requires_pre_approval',
        'min_advance_notice_days', 'allows_negative_balance',
        'carries_over_to_next_cycle', 'forfeit_after_months',
        'payout_on_termination', 'affects_payroll',
        'is_system', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_paid'                            => 'boolean',
        'is_uif_claimable'                   => 'boolean',
        'requires_documentation'             => 'boolean',
        'accrual_starts_at_employment_date'  => 'boolean',
        'requires_pre_approval'              => 'boolean',
        'allows_negative_balance'            => 'boolean',
        'carries_over_to_next_cycle'         => 'boolean',
        'payout_on_termination'              => 'boolean',
        'affects_payroll'                    => 'boolean',
        'is_system'                          => 'boolean',
        'is_active'                          => 'boolean',
        'entitlement_days_per_cycle'         => 'decimal:2',
        'entitlement_days_per_cycle_six_day' => 'decimal:2',
        'documentation_threshold_days'       => 'integer',
        'cycle_months'                       => 'integer',
        'accrual_rate_per_days'              => 'integer',
        'min_advance_notice_days'            => 'integer',
        'forfeit_after_months'               => 'integer',
        'sort_order'                         => 'integer',
    ];

    // ── Relationships ──

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(LeaveEntitlement::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ── Methods ──

    public function isStatutory(): bool
    {
        return (bool) $this->is_system;
    }

    public function entitlementForPattern(int $daysPerWeek): float
    {
        if ($daysPerWeek >= 6) {
            return (float) $this->entitlement_days_per_cycle_six_day;
        }
        if ($daysPerWeek <= 5) {
            return (float) $this->entitlement_days_per_cycle;
        }
        return (float) $this->entitlement_days_per_cycle;
    }

    public function delete()
    {
        if ($this->is_system) {
            abort(403, 'System leave types cannot be deleted. Deactivate instead.');
        }

        if ($this->applications()->exists() || $this->transactions()->exists()) {
            abort(422, 'Leave type has applications or transactions and cannot be deleted. Deactivate instead.');
        }

        return parent::delete();
    }
}
