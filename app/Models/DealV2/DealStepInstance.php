<?php

namespace App\Models\DealV2;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class DealStepInstance extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'deal_id',
        'pipeline_step_id',
        'name',
        'description',
        'position',
        'is_locked',
        'is_milestone',
        'completion_type',
        'completion_config',
        'status',
        'trigger_type',
        'trigger_step_instance_id',
        'days_offset',
        'due_date',
        'activated_at',
        'completed_at',
        'completed_by_id',
        'completion_data',
        'rag_green_days',
        'rag_amber_days',
        'rag_red_days',
        'current_rag',
        'notify_agent',
        'notify_bm',
        'notify_admin',
        'status_trigger',
        'negative_status_trigger',
        'negative_outcome_label',
        'requires_bm_approval',
        'approval_status',
        'approved_by_id',
        'approved_at',
        'approval_notes',
        'notes',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_milestone' => 'boolean',
        'notify_agent' => 'boolean',
        'notify_bm' => 'boolean',
        'notify_admin' => 'boolean',
        'requires_bm_approval' => 'boolean',
        'completion_config' => 'array',
        'completion_data' => 'array',
        'due_date' => 'date',
        'activated_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // ── Relationships ──

    public function deal(): BelongsTo
    {
        return $this->belongsTo(DealV2::class, 'deal_id');
    }

    public function pipelineStep(): BelongsTo
    {
        return $this->belongsTo(DealPipelineStep::class, 'pipeline_step_id');
    }

    public function triggerStepInstance(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trigger_step_instance_id');
    }

    public function dependentSteps(): HasMany
    {
        return $this->hasMany(self::class, 'trigger_step_instance_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DealStepDocument::class, 'deal_step_instance_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    // ── Methods ──

    public function needsApproval(): bool
    {
        return $this->requires_bm_approval && $this->approval_status === 'pending';
    }

    public function isApproved(): bool
    {
        return !$this->requires_bm_approval || $this->approval_status === 'approved';
    }

    public function daysRemaining(): ?int
    {
        if (!$this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }

    public function calculateRag(): string
    {
        if ($this->status === 'completed' || $this->status === 'skipped') {
            return 'grey';
        }

        if ($this->status === 'not_started') {
            return 'grey';
        }

        $remaining = $this->daysRemaining();

        if ($remaining === null) {
            return 'grey';
        }

        if ($remaining < 0) {
            return 'overdue';
        }

        if ($remaining <= $this->rag_red_days) {
            return 'red';
        }

        if ($remaining <= $this->rag_amber_days) {
            return 'amber';
        }

        return 'green';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue' || ($this->due_date && $this->due_date->isPast() && !in_array($this->status, ['completed', 'skipped']));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
