<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositInterestCalculation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'property_name',
        'deposit_amount',
        'invest_date',
        'refund_date',
        'topups',
        'total_deposited',
        'total_interest',
        'grand_total',
        'breakdown',
        'notes',
    ];

    protected $casts = [
        'invest_date' => 'date',
        'refund_date' => 'date',
        'topups' => 'array',
        'breakdown' => 'array',
        'deposit_amount' => 'decimal:2',
        'total_deposited' => 'decimal:2',
        'total_interest' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
