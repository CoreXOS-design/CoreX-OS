<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class DealSettlement extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'deal_id',
        'user_id',
        'side',
        'share_percent',
        'agent_cut_percent',
        'paye_method',
        'paye_value',
        'deductions',
        'deductions_description',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
