<?php

namespace App\Models\DealV2;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-229 COC sub-process — a per-DEAL work order selected on the live pipeline: which COC, who
 * is responsible, who it is emailed to. Distinct from the step-TEMPLATE config
 * (DealPipelineStepWorkOrder, which only lists the COC types a step offers).
 */
class DealStepWorkOrder extends Model
{
    use SoftDeletes;

    protected $table = 'deal_step_work_orders';

    public const RESPONSIBLE = ['seller', 'listing_agent', 'selling_agent', 'supplier', 'transfer_attorney'];

    protected $fillable = [
        'deal_step_instance_id', 'trigger_step_instance_id', 'dr1_deal_id', 'agency_id',
        'service_type', 'responsible_party', 'service_provider_id',
        'recipient_name', 'recipient_email', 'cc_emails',
        'status', 'document_id', 'sent_at', 'sent_by_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(DealStepInstance::class, 'deal_step_instance_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'service_provider_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }
}
