<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayslipLine extends Model
{
    protected $fillable = [
        'payroll_payslip_id',
        'line_type',
        'source_type_id',
        'code_snapshot',
        'label_snapshot',
        'sars_source_code_snapshot',
        'amount',
        'is_taxable_snapshot',
        'sort_order',
    ];

    protected $casts = [
        'amount'              => 'decimal:2',
        'is_taxable_snapshot' => 'boolean',
        'sort_order'          => 'integer',
    ];

    // ── Relationships ──

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(PayrollPayslip::class, 'payroll_payslip_id');
    }

    /**
     * Resolve the source type model based on line_type.
     * Returns PayrollEarningType for 'earning' and 'employer_contribution',
     * PayrollDeductionType for 'deduction'.
     */
    public function sourceType(): ?Model
    {
        if (in_array($this->line_type, ['earning', 'employer_contribution'])) {
            return PayrollEarningType::find($this->source_type_id);
        }

        if ($this->line_type === 'deduction') {
            return PayrollDeductionType::find($this->source_type_id);
        }

        return null;
    }
}
