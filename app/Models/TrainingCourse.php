<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class TrainingCourse extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'title',
        'description',
        'category',
        'is_required',
        'is_required_for_activation',
        'sort_order',
        'is_published',
        'created_by',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_required_for_activation' => 'boolean',
        'is_published' => 'boolean',
    ];

    public const CATEGORY_LABELS = [
        'compliance' => 'Compliance',
        'onboarding' => 'Onboarding',
        'sales' => 'Sales',
        'systems' => 'Systems',
        'general' => 'General',
    ];

    public const CATEGORY_COLORS = [
        'compliance' => ['bg' => 'rgba(239,68,68,0.12)', 'color' => '#ef4444'],
        'onboarding' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b'],
        'sales' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6'],
        'systems' => ['bg' => 'rgba(168,85,247,0.12)', 'color' => '#a855f7'],
        'general' => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8'],
    ];

    // ── Relationships ──

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function lessons()
    {
        return $this->hasMany(TrainingLesson::class, 'course_id')->orderBy('sort_order');
    }

    public function completions()
    {
        return $this->hasMany(TrainingCompletion::class, 'course_id');
    }

    public function progress()
    {
        return $this->hasMany(TrainingProgress::class, 'course_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    // ── Methods ──

    public function lessonCount(): int
    {
        return $this->lessons()->where('is_published', true)->count();
    }

    public function completedLessonCountForUser(int $userId): int
    {
        return TrainingProgress::where('user_id', $userId)
            ->where('course_id', $this->id)
            ->whereNotNull('completed_at')
            ->count();
    }

    public function completionPercentForUser(int $userId): int
    {
        $total = $this->lessonCount();
        if ($total === 0) {
            return 100;
        }
        $completed = $this->completedLessonCountForUser($userId);

        return (int) round(($completed / $total) * 100);
    }

    public function isCompletedByUser(int $userId): bool
    {
        return TrainingCompletion::where('user_id', $userId)
            ->where('course_id', $this->id)
            ->exists();
    }

    public function completionForUser(int $userId): ?TrainingCompletion
    {
        return TrainingCompletion::where('user_id', $userId)
            ->where('course_id', $this->id)
            ->first();
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? ucfirst($this->category);
    }

    public function totalDurationMinutes(): int
    {
        return $this->lessons()->where('is_published', true)->sum('duration_minutes');
    }
}
