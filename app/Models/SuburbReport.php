<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An immutable, frozen-at-generation suburb report. Never updated after
 * creation — generating again creates a new row. See the creating
 * migration's doc comment for the freeze rationale.
 */
class SuburbReport extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'p24_suburb_id',
        'suburb_name',
        'municipality',
        'municipality_confirmed',
        'agency_name',
        'generated_by_user_id',
        'generated_at',
        'current_year_at_generation',
        'layer_a_json',
        'layer_a_source_vintage',
        'layer_b_json',
        'layer_b_as_at',
        'layer_c_json',
        'layer_c_as_at',
    ];

    protected $casts = [
        'layer_a_json' => 'array',
        'layer_b_json' => 'array',
        'layer_c_json' => 'array',
        'municipality_confirmed' => 'boolean',
        'generated_at' => 'datetime',
        'layer_a_source_vintage' => 'date',
        'layer_b_as_at' => 'datetime',
        'layer_c_as_at' => 'datetime',
    ];

    public function suburb()
    {
        return $this->belongsTo(P24Suburb::class, 'p24_suburb_id');
    }
}
