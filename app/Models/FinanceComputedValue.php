<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class FinanceComputedValue extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'definition_id',
        'definition_key',
        'definition_version',
        'entity_type',
        'entity_id',
        'period',
        'value_numeric',
        'value_json',
        'input_hash',
        'engine_version',
        'computed_at',
        'audit_run_id',
    ];

    protected $casts = [
        'value_json' => 'array',
        'value_numeric' => 'decimal:6',
        'computed_at' => 'datetime',
    ];

    public function definition()
    {
        return $this->belongsTo(FinanceDefinition::class, 'definition_id');
    }
}
