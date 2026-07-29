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
        // AT-334 master-catalog — inline step definition (self-contained; no deal_pipeline_steps link).
        'step_key', 'name', 'follows_key', 'deps_keys', 'days_offset',
        'is_milestone', 'is_suspensive', 'is_anchor', 'completion_type', 'status_trigger',
        'manual_due_option', 'requires_option', 'requires_funds_mode', 'expand',
    ];

    protected $casts = [
        'is_grant_marker' => 'boolean',
        'position'        => 'integer',
        'deps_keys'       => 'array',
        'days_offset'     => 'integer',
        'is_milestone'    => 'boolean',
        'is_suspensive'   => 'boolean',
        'is_anchor'       => 'boolean',
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
