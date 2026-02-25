<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialEvaluationCrop extends Model
{
    protected $fillable = [
        'commercial_evaluation_id',
        'crop_type',
        'variety',
        'hectares',
        'year_planted',
        'age_years',
        'expected_lifespan_years',
        'remaining_productive_years',
        'trees_per_hectare',
        'total_trees',
        'current_yield_tons_per_ha',
        'expected_peak_yield_tons_per_ha',
        'yield_percentage',
        'current_price_per_ton',
        'annual_revenue',
        'annual_cost_per_ha',
        'notes',
        'guidance_answers',
    ];

    protected $casts = [
        'hectares'                       => 'decimal:2',
        'year_planted'                   => 'integer',
        'age_years'                      => 'integer',
        'expected_lifespan_years'        => 'integer',
        'remaining_productive_years'     => 'integer',
        'trees_per_hectare'              => 'integer',
        'total_trees'                    => 'integer',
        'current_yield_tons_per_ha'      => 'decimal:2',
        'expected_peak_yield_tons_per_ha'=> 'decimal:2',
        'yield_percentage'               => 'decimal:2',
        'current_price_per_ton'          => 'integer',
        'annual_revenue'                 => 'integer',
        'annual_cost_per_ha'             => 'integer',
        'guidance_answers'               => 'array',
    ];

    public function evaluation()
    {
        return $this->belongsTo(CommercialEvaluation::class, 'commercial_evaluation_id');
    }
}
