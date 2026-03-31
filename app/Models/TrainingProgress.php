<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingProgress extends Model
{
    protected $table = 'training_progress';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    public function lesson()
    {
        return $this->belongsTo(TrainingLesson::class, 'lesson_id');
    }
}
