<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class CommercialEvaluationComparable extends Model
{
    use BelongsToAgency, SoftDeletes;


    protected $table = 'commercial_evaluation_comparables';

    protected $fillable = [
        'agency_id',
        'commercial_evaluation_id',
        'address',
        'suburb',
        'property_type',
        'size_m2',
        'size_ha',
        'sale_price',
        'sale_date',
        'price_per_m2',
        'price_per_ha',
        'notes',
        'source',
    ];

    protected $casts = [
        'sale_price'   => 'integer',
        'price_per_m2' => 'integer',
        'price_per_ha' => 'integer',
        'size_m2'      => 'decimal:2',
        'size_ha'      => 'decimal:4',
        'sale_date'    => 'date',
    ];

    public function evaluation()
    {
        return $this->belongsTo(CommercialEvaluation::class, 'commercial_evaluation_id');
    }
}
