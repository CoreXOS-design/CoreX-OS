<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealMoneyLine extends Model
{
    protected $table = 'deal_money_lines';

    protected $fillable = [
        'deal_id',
        'user_id',
        'period',
        'branch_id',
        'side',
        'side_pool_ex_vat',
        'allocation_percent',
        'pool_share_ex_vat',
        'agent_cut_percent',
        'agent_gross_ex_vat',
        'company_gross_ex_vat',
        'paye_method',
        'paye_value',
        'paye_amount',
        'deductions',
        'deductions_description',
        'agent_net_ex_vat',
        'source',
        'paid_at',
    ];

    protected $casts = [
        'deal_id' => 'integer',
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'side_pool_ex_vat' => 'decimal:2',
        'allocation_percent' => 'decimal:2',
        'pool_share_ex_vat' => 'decimal:2',
        'agent_cut_percent' => 'decimal:2',
        'agent_gross_ex_vat' => 'decimal:2',
        'company_gross_ex_vat' => 'decimal:2',
        'paye_value' => 'decimal:2',
        'paye_amount' => 'decimal:2',
        'deductions' => 'decimal:2',
        'agent_net_ex_vat' => 'decimal:2',
        'paid_at' => 'datetime',
    ];
}
