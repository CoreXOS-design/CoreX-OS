<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-229 — one work-order entry configured on a pipeline step. A step may carry SEVERAL
 * (e.g. a "Certificates of Compliance" step → Electrical + Gas + Beetle + Plumbing). Each
 * entry declares WHAT (service_type) and WHEN (trigger_point); the supplier is still chosen
 * or captured at send time (Q2 — never stored on the config).
 */
class DealPipelineStepWorkOrder extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'deal_pipeline_step_work_orders';

    protected $fillable = [
        'pipeline_step_id',
        'agency_id',
        'service_type',
        'trigger_point',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(DealPipelineStep::class, 'pipeline_step_id');
    }
}
