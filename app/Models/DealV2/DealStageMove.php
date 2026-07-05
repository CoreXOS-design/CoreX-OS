<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-158 WS-V2 — the record of a deal-stage advance (auto, prompt-pending,
 * confirmed, or undone). This is the audit + one-click-undo spine for the
 * suspensive-conditions stage gate.
 */
class DealStageMove extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'deal_id', 'from_status', 'to_status', 'reason',
        'trigger_step_instance_id', 'mode', 'state',
        'moved_by_id', 'moved_at', 'undone_by_id', 'undone_at', 'note',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
        'undone_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(DealV2::class, 'deal_id');
    }

    public function triggerStep(): BelongsTo
    {
        return $this->belongsTo(DealStepInstance::class, 'trigger_step_instance_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by_id');
    }

    /** An applied auto/confirmed move can be undone; a pending or already-undone one cannot. */
    public function isUndoable(): bool
    {
        return in_array($this->state, ['applied', 'confirmed'], true) && $this->undone_at === null;
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }
}
