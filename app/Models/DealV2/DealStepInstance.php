<?php

namespace App\Models\DealV2;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class DealStepInstance extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'deal_id',
        'dr1_deal_id',
        'pipeline_step_id',
        'name',
        'description',
        'position',
        'is_locked',
        'is_milestone',
        'is_custom',
        'is_suspensive',
        'completion_type',
        'completion_config',
        'status',
        'na_reason',
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
        'is_custom' => 'boolean',
        'is_suspensive' => 'boolean',
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

    /**
     * AT-216: DR2 pipeline anchor — the DR1 deal this step belongs to.
     * Coexists with the legacy deals_v2 anchor (`deal()`) until AT-219 sunset.
     */
    public function dr1Deal(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Deal::class, 'dr1_deal_id');
    }

    public function pipelineStep(): BelongsTo
    {
        return $this->belongsTo(DealPipelineStep::class, 'pipeline_step_id');
    }

    /** AT-216 V1.1 — per-step comment thread (newest last). */
    public function comments()
    {
        return $this->hasMany(DealStepComment::class, 'deal_step_instance_id')->orderBy('created_at');
    }

    public function triggerStepInstance(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trigger_step_instance_id');
    }

    public function dependentSteps(): HasMany
    {
        return $this->hasMany(self::class, 'trigger_step_instance_id');
    }

    /**
     * AT-158 WS-V1 — additional AND-gate predecessors (beyond the single primary
     * `trigger_step_instance_id`). This step activates only when its primary
     * trigger AND all of these are complete. Empty for the common linear case.
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'deal_step_instance_dependencies',
            'deal_step_instance_id',
            'depends_on_step_instance_id',
        )->withTimestamps();
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

    // ── AND-gate (WS-V1) ──

    /**
     * All predecessor instances this step waits on: the single primary trigger
     * (if any) plus every additional AND-gate dependency. Reads from loaded
     * relations where present, so the caller controls query cost.
     */
    public function predecessorInstances()
    {
        $preds = collect();

        if ($this->trigger_step_instance_id) {
            $primary = $this->relationLoaded('triggerStepInstance')
                ? $this->triggerStepInstance
                : $this->triggerStepInstance()->first();
            if ($primary) {
                $preds->push($primary);
            }
        }

        $deps = $this->relationLoaded('dependencies') ? $this->dependencies : $this->dependencies()->get();
        foreach ($deps as $d) {
            $preds->push($d);
        }

        return $preds->unique('id')->values();
    }

    /** Predecessors that are NOT yet complete — the reason this step is still blocked. */
    public function blockingPredecessors()
    {
        return $this->predecessorInstances()->reject(fn ($p) => $p->status === 'completed')->values();
    }

    /** Human "waiting on …" label for a blocked (not_started) step; null when nothing blocks it. */
    public function blockedByLabel(): ?string
    {
        $preds = $this->predecessorInstances();
        if ($preds->isEmpty()) {
            return null;
        }
        $blocking = $preds->reject(fn ($p) => $p->status === 'completed');
        if ($blocking->isEmpty()) {
            return null;
        }
        $names = $blocking->pluck('name')->all();
        $done = $preds->count() - $blocking->count();

        // Single linear dependency keeps the familiar "+ N days" phrasing.
        if ($preds->count() === 1) {
            return "Waiting on \"{$names[0]}\"";
        }

        return 'Waiting on ' . implode(', ', $names) . " ({$done} of {$preds->count()} done)";
    }
}
