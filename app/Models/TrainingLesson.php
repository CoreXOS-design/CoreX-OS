<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingLesson extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function course()
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    public function progressRecords()
    {
        return $this->hasMany(TrainingProgress::class, 'lesson_id');
    }

    public function progressForUser(int $userId): ?TrainingProgress
    {
        return TrainingProgress::where('user_id', $userId)
            ->where('lesson_id', $this->id)
            ->first();
    }
}
