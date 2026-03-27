<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingChecklist extends Model
{
    protected $guarded = [];

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
