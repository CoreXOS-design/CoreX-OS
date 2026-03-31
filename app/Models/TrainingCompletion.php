<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingCompletion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'expires_at' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    // ── Scopes ──

    public function scopeExpiring($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30)->toDateString())
            ->where('expires_at', '>', now()->toDateString());
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->toDateString());
    }
}
