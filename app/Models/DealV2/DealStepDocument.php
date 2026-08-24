<?php

namespace App\Models\DealV2;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
class DealStepDocument extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency;

    /** Branch follows the parent step instance (→ its deal); spec §7a. */
    protected function branchParent(): array
    {
        return [DealStepInstance::class, 'deal_step_instance_id'];
    }

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'deal_step_instance_id',
        'document_id',
        'file_path',
        'file_name',
        'uploaded_by_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(DealStepInstance::class, 'deal_step_instance_id');
    }

    /**
     * AT-158 WS3 (D4) — the unified document this step-file is backed by.
     * Nullable: legacy step-files carry only a raw file_path; the spine
     * populates document_id so the same file is reachable from the deal,
     * property and contacts, not just this step.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Document::class, 'document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
