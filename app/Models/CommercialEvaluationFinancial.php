<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class CommercialEvaluationFinancial extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'commercial_evaluation_financials';

    protected $fillable = [
        'agency_id',
        'commercial_evaluation_id',
        'financial_year',
        'period_months',
        'gross_revenue',
        'rental_income',
        'room_revenue',
        'food_beverage_revenue',
        'other_income',
        'vacancy_rate',
        'rates_taxes',
        'insurance',
        'utilities',
        'maintenance',
        'management_fees',
        'salaries_wages',
        'security',
        'marketing',
        'food_beverage_cost',
        'farm_operating_costs',
        'other_expenses',
        'total_expenses',
        'net_operating_income',
        'ebitda',
    ];

    protected $casts = [
        'period_months'        => 'integer',
        'gross_revenue'        => 'integer',
        'rental_income'        => 'integer',
        'room_revenue'         => 'integer',
        'food_beverage_revenue'=> 'integer',
        'other_income'         => 'integer',
        'vacancy_rate'         => 'decimal:2',
        'rates_taxes'          => 'integer',
        'insurance'            => 'integer',
        'utilities'            => 'integer',
        'maintenance'          => 'integer',
        'management_fees'      => 'integer',
        'salaries_wages'       => 'integer',
        'security'             => 'integer',
        'marketing'            => 'integer',
        'food_beverage_cost'   => 'integer',
        'farm_operating_costs' => 'integer',
        'other_expenses'       => 'integer',
        'total_expenses'       => 'integer',
        'net_operating_income'  => 'integer',
        'ebitda'               => 'integer',
    ];

    public function evaluation()
    {
        return $this->belongsTo(CommercialEvaluation::class, 'commercial_evaluation_id');
    }
}
