<?php

namespace App\Models\DealV2;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DealV2Settlement extends Model
{
    use SoftDeletes;

    protected $table = 'deal_v2_settlements';

    protected $fillable = [
        'deal_id',
        'user_id',
        'side',
        'share_percent',
        'agent_cut_percent',
        'paye_method',
        'paye_value',
        'deductions',
        'deductions_description',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(DealV2::class, 'deal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
