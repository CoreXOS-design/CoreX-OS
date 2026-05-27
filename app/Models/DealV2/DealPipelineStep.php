<?php

namespace App\Models\DealV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class DealPipelineStep extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'pipeline_template_id',
        'name',
        'description',
        'position',
        'is_locked',
        'is_milestone',
        'completion_type',
        'completion_config',
        'trigger_type',
        'trigger_step_id',
        'days_offset',
        'rag_green_days',
        'rag_amber_days',
        'rag_red_days',
        'notify_agent',
        'notify_bm',
        'notify_admin',
        'status_trigger',
        'negative_status_trigger',
        'negative_outcome_label',
        'requires_bm_approval',
        'escalation_config',
        'required_before',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_milestone' => 'boolean',
        'notify_agent' => 'boolean',
        'notify_bm' => 'boolean',
        'notify_admin' => 'boolean',
        'requires_bm_approval' => 'boolean',
        'completion_config' => 'array',
        'escalation_config' => 'array',
        'required_before' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DealPipelineTemplate::class, 'pipeline_template_id');
    }

    public function triggerStep(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trigger_step_id');
    }

    public function dependentSteps(): HasMany
    {
        return $this->hasMany(self::class, 'trigger_step_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(DealStepInstance::class, 'pipeline_step_id');
    }
}
