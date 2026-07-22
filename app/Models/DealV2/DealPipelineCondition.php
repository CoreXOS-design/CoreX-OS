<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AT-334 — a suspensive-condition pack a pipeline template offers
 * (cash / bond / sale_of_another / deposit). Template layer.
 */
class DealPipelineCondition extends Model
{
    use BelongsToAgency;

    protected $table = 'deal_pipeline_conditions';

    protected $fillable = [
        'pipeline_template_id', 'agency_id', 'key', 'label', 'is_default', 'options_schema',
    ];

    protected $casts = [
        'is_default'     => 'boolean',
        'options_schema' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DealPipelineTemplate::class, 'pipeline_template_id');
    }

    public function conditionSteps(): HasMany
    {
        return $this->hasMany(DealPipelineConditionStep::class, 'condition_id')->orderBy('position');
    }
}
