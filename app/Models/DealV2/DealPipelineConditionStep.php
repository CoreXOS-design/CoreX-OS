<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-334 — maps a template step to a condition pack, and flags the Granted marker.
 */
class DealPipelineConditionStep extends Model
{
    use BelongsToAgency;

    protected $table = 'deal_pipeline_condition_steps';

    protected $fillable = [
        'condition_id', 'pipeline_step_id', 'agency_id', 'position', 'is_grant_marker',
    ];

    protected $casts = [
        'is_grant_marker' => 'boolean',
        'position'        => 'integer',
    ];

    public function condition(): BelongsTo
    {
        return $this->belongsTo(DealPipelineCondition::class, 'condition_id');
    }

    public function pipelineStep(): BelongsTo
    {
        return $this->belongsTo(DealPipelineStep::class, 'pipeline_step_id');
    }
}
