<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class CommercialEvaluationLivestock extends Model
{
    use BelongsToAgency, SoftDeletes;


    protected $table = 'commercial_evaluation_livestock';

    protected $fillable = [
        'agency_id',
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
        'guidance_answers',
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
        'guidance_answers'            => 'array',
    ];

    public function evaluation()
    {
        return $this->belongsTo(CommercialEvaluation::class, 'commercial_evaluation_id');
    }
}
