<?php

namespace App\Models\Payroll;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PayrollTaxRebate extends Model
{
    protected $fillable = [
        'tax_year_start',
        'primary_rebate',
        'secondary_rebate',
        'tertiary_rebate',
        'tax_threshold_under_65',
        'tax_threshold_65_74',
        'tax_threshold_75_plus',
        'medical_credit_main',
        'medical_credit_additional',
        'uif_ceiling_monthly',
        'uif_rate_percent',
        'sdl_threshold_annual',
        'sdl_rate_percent',
    ];

    protected $casts = [
        'tax_year_start'            => 'date',
        'primary_rebate'            => 'decimal:2',
        'secondary_rebate'          => 'decimal:2',
        'tertiary_rebate'           => 'decimal:2',
        'tax_threshold_under_65'    => 'decimal:2',
        'tax_threshold_65_74'       => 'decimal:2',
        'tax_threshold_75_plus'     => 'decimal:2',
        'medical_credit_main'       => 'decimal:2',
        'medical_credit_additional' => 'decimal:2',
        'uif_ceiling_monthly'       => 'decimal:2',
        'uif_rate_percent'          => 'decimal:3',
        'sdl_threshold_annual'      => 'decimal:2',
        'sdl_rate_percent'          => 'decimal:3',
    ];

    // ── Scopes ──

    public function scopeForTaxYear($query, Carbon $date)
    {
        // AT-237 C3 — bound to the ACTUAL tax year window (start .. start + 1yr),
        // symmetric with PayrollTaxTable::forTaxYear. Without the upper bound this
        // silently forward-filled the previous year's rebate / UIF ceiling / SDL
        // rate forever, so an unseeded new tax year produced a silently-wrong
        // payslip. Now an out-of-range period returns NOTHING → hard stop upstream.
        return $query->where('tax_year_start', '<=', $date->toDateString())
            ->whereRaw('DATE_ADD(tax_year_start, INTERVAL 1 YEAR) > ?', [$date->toDateString()])
            ->orderByDesc('tax_year_start')
            ->limit(1);
    }
}
