<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingChecklist extends Model
{
    protected $fillable = [
        'application_id',
        'item_key',
        'item_label',
        'is_required',
        'is_completed',
        'completed_at',
        'completed_by',
        'notes',
        'sort_order',
    ];
    // NOTE: is_completed / completed_at / completed_by are written by toggle endpoints —
    // included so the existing flow keeps working. Tighten further if those endpoints
    // are refactored to use explicit setters.

    protected $casts = [
        'is_required' => 'boolean',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    // ── Relationships ──

    public function application()
    {
        return $this->belongsTo(AgentApplication::class, 'application_id');
    }

    public function completedByUser()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
