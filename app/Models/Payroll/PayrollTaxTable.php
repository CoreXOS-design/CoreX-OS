<?php

namespace App\Models\Payroll;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PayrollTaxTable extends Model
{
    protected $fillable = [
        'tax_year_start',
        'tax_year_end',
        'bracket_order',
        'income_from',
        'income_to',
        'base_tax',
        'rate_percent',
    ];

    protected $casts = [
        'tax_year_start' => 'date',
        'tax_year_end'   => 'date',
        'income_from'    => 'decimal:2',
        'income_to'      => 'decimal:2',
        'base_tax'       => 'decimal:2',
        'rate_percent'   => 'decimal:2',
        'bracket_order'  => 'integer',
    ];

    // ── Scopes ──

    public function scopeForTaxYear($query, Carbon $date)
    {
        return $query->where('tax_year_start', '<=', $date->toDateString())
            ->where('tax_year_end', '>=', $date->toDateString())
            ->orderBy('bracket_order');
    }
}
