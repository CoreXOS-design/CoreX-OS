<?php

namespace App\Models\Payroll;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollPayslip extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'agency_id',
        'branch_id',
        'payroll_run_id',
        'payroll_employee_id',
        'user_id',
        'payslip_number',
        'employee_name_snapshot',
        'id_number_snapshot',
        'tax_reference_snapshot',
        'employment_date_snapshot',
        'designation_snapshot',
        'period_month',
        'pay_date',
        'total_earnings',
        'total_deductions',
        'taxable_income',
        'paye_amount',
        'uif_employee_amount',
        'uif_employer_amount',
        'sdl_amount',
        'net_pay',
        'document_id',
        'pdf_generated_at',
        'notes',
    ];

    protected $casts = [
        'employment_date_snapshot' => 'date',
        'period_month'            => 'date',
        'pay_date'                => 'date',
        'pdf_generated_at'        => 'datetime',
        'total_earnings'          => 'decimal:2',
        'total_deductions'        => 'decimal:2',
        'taxable_income'          => 'decimal:2',
        'paye_amount'             => 'decimal:2',
        'uif_employee_amount'     => 'decimal:2',
        'uif_employer_amount'     => 'decimal:2',
        'sdl_amount'              => 'decimal:2',
        'net_pay'                 => 'decimal:2',
    ];

    // ── Relationships ──

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollPayslipLine::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(PayrollPayslipLine::class)
            ->where('line_type', 'earning');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(PayrollPayslipLine::class)
            ->where('line_type', 'deduction');
    }

    public function employerContributions(): HasMany
    {
        return $this->hasMany(PayrollPayslipLine::class)
            ->where('line_type', 'employer_contribution');
    }

    // ── Scopes ──

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    // ── Helpers ──

    public function isImmutable(): bool
    {
        return $this->run && $this->run->isFinalised();
    }
}
