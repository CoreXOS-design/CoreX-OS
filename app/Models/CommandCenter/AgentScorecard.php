<?php

namespace App\Models\CommandCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class AgentScorecard extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'user_id', 'period_type', 'period_start', 'period_end',
        'tasks_completed', 'tasks_overdue', 'tasks_total',
        'properties_attended', 'properties_total',
        'documents_uploaded', 'fica_complete', 'fica_total',
        'avg_response_hours', 'deals_progressed',
        'events_completed', 'events_total', 'activity_points',
        'overall_score', 'computed_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'computed_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWeekly($query)
    {
        return $query->where('period_type', 'weekly');
    }

    public function scopeMonthly($query)
    {
        return $query->where('period_type', 'monthly');
    }

    public function scopeCurrentWeek($query)
    {
        return $query->where('period_type', 'weekly')
                     ->where('period_start', now()->startOfWeek()->toDateString());
    }

    public function scopeCurrentMonth($query)
    {
        return $query->where('period_type', 'monthly')
                     ->where('period_start', now()->startOfMonth()->toDateString());
    }
}
