<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-158 DR2 · WS4 (§4.5, §8.1) — a distribution-matrix rule.
 *
 * STAGE (pipeline_step) × DOCUMENT TYPE × PARTY ROLE → {delivery_mode,
 * auto_on_stage_tick}. pipeline_step_id NULL = "any stage / manual only".
 */
class DealStageDocumentRule extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'pipeline_step_id',
        'document_type_id',
        'party_role',
        'delivery_mode',
        'auto_on_stage_tick',
        'is_active',
        'created_by_id',
    ];

    protected $casts = [
        'auto_on_stage_tick' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const MODE_SECURE_LINK = 'secure_link';
    public const MODE_DIRECT_ATTACHMENT = 'direct_attachment';

    public function pipelineStep(): BelongsTo
    {
        return $this->belongsTo(DealPipelineStep::class, 'pipeline_step_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
