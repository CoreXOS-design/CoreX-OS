<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepositTrustInterest extends Model
{
    use SoftDeletes;

    protected $table = 'deposit_trust_interest';

    protected $fillable = [
        'interest_date',
        'total_invested_funds',
        'interest_earned',
    ];

    protected $casts = [
        'interest_date' => 'date',
        'total_invested_funds' => 'decimal:2',
        'interest_earned' => 'decimal:2',
    ];

    public function scopeDefaultOrder($query)
    {
        return $query->orderBy('interest_date', 'desc');
    }
}
