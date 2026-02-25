<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialEvaluationLivestock extends Model
{
    protected $table = 'commercial_evaluation_livestock';

    protected $fillable = [
        'commercial_evaluation_id',
        'livestock_type',
        'breed',
        'head_count',
        'breeding_stock_count',
        'value_per_head',
        'total_value',
        'carrying_capacity_ha_per_lsu',
        'hectares_used',
        'annual_revenue',
        'annual_cost',
        'notes',
    ];

    protected $casts = [
        'head_count'                  => 'integer',
        'breeding_stock_count'        => 'integer',
        'value_per_head'              => 'integer',
        'total_value'                 => 'integer',
        'carrying_capacity_ha_per_lsu'=> 'decimal:2',
        'hectares_used'               => 'decimal:2',
        'annual_revenue'              => 'integer',
        'annual_cost'                 => 'integer',
    ];

    public function evaluation()
    {
        return $this->belongsTo(CommercialEvaluation::class, 'commercial_evaluation_id');
    }
}
