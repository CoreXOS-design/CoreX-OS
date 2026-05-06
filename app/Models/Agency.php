<?php

namespace App\Models;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\RmcpVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agency extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'trading_name',
        'tagline',
        'address',
        'phone',
        'phone_label',
        'phone_secondary',
        'phone_secondary_label',
        'fax',
        'email',
        'reg_no',
        'vat_no',
        'ffc_no',
        'fic_no',
        'sidebar_color',
        'icon_color',
        'default_color',
        'button_color',
        'logo_path',
        'email_disclaimer',
        'popi_url',
        'is_active',
        'dashboard_settings_mode',
        'split_branches_enabled',
        'p24_agency_id',
        'p24_agency_label',
        'default_branch_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'split_branches_enabled' => 'boolean',
        'default_branch_id' => 'integer',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function defaultBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function rmcpVersions(): HasMany
    {
        return $this->hasMany(RmcpVersion::class);
    }

    public function currentRmcpVersion(): HasOne
    {
        return $this->hasOne(RmcpVersion::class)->where('status', 'active');
    }

    public function complianceOfficer(): HasOne
    {
        return $this->hasOne(FicaOfficerAppointment::class)
            ->where('role', FicaOfficerAppointment::ROLE_PRIMARY)
            ->whereNull('ended_on');
    }

    // ── Payroll ──

    public function payrollEmployees(): HasMany
    {
        return $this->hasMany(Payroll\PayrollEmployee::class);
    }

    public function payrollRuns(): HasMany
    {
        return $this->hasMany(Payroll\PayrollRun::class);
    }

    public function earningTypes(): HasMany
    {
        return $this->hasMany(Payroll\PayrollEarningType::class);
    }

    public function deductionTypes(): HasMany
    {
        return $this->hasMany(Payroll\PayrollDeductionType::class);
    }

    /**
     * Check if agency's total annual gross payroll meets the SDL threshold.
     * Based on last 12 months of finalised payroll runs vs current tax rebate data.
     */
    public function hasSdlObligation(): bool
    {
        $rebate = Payroll\PayrollTaxRebate::forTaxYear(now())->first();
        if (! $rebate) {
            return false;
        }

        $annualGross = $this->payrollRuns()
            ->finalised()
            ->where('period_month', '>=', now()->subMonths(12)->startOfMonth()->toDateString())
            ->sum('total_gross');

        return bccomp((string) $annualGross, (string) $rebate->sdl_threshold_annual, 2) >= 0;
    }

    // ── Leave ──

    public function leaveTypes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\LeaveType::class);
    }

    public function leaveApplications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\LeaveApplication::class);
    }

    public function leaveTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\LeaveTransaction::class);
    }

    public function staffTakeOnRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\StaffTakeOnRecord::class);
    }
}
