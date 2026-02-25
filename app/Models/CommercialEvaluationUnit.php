<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialEvaluationUnit extends Model
{
    protected $table = 'commercial_evaluation_units';

    protected $fillable = [
        'commercial_evaluation_id',
        'unit_name',
        'tenant_name',
        'size_m2',
        'monthly_rental',
        'lease_start',
        'lease_end',
        'is_vacant',
        'escalation_rate',
        'notes',
    ];

    protected $casts = [
        'monthly_rental'  => 'integer',
        'is_vacant'       => 'boolean',
        'size_m2'         => 'decimal:2',
        'escalation_rate' => 'decimal:2',
        'lease_start'     => 'date',
        'lease_end'       => 'date',
    ];

    public function evaluation()
    {
        return $this->belongsTo(CommercialEvaluation::class, 'commercial_evaluation_id');
    }
}
