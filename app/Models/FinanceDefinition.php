<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceDefinition extends Model
{
    protected $fillable = [
        'key',
        'version',
        'status',
        'entity_type',
        'value_type',
        'expression',
        'dependencies',
        'rounding_scale',
        'notes',
    ];

    protected $casts = [
        'dependencies' => 'array',
        'version' => 'integer',
        'rounding_scale' => 'integer',
    ];

    public function computedValues()
    {
        return $this->hasMany(FinanceComputedValue::class, 'definition_id');
    }
}
