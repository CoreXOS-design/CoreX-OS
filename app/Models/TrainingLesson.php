<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingLesson extends Model
{
    protected $fillable = [
        'course_id',
        'title',
        'content',
        'content_type',
        'video_url',
        'document_path',
        'external_link',
        'duration_minutes',
        'sort_order',
        'is_published',
    ];

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

    /**
     * 2026-09-30 — the lesson-create form lets an author paste whatever
     * share link YouTube gave them (a /watch?v=, youtu.be/, or /shorts/
     * URL), but the learner view used to iframe $video_url verbatim, which
     * only ever worked for an already-built /embed/ URL. Extracts the
     * video id from any of the three common share-link shapes (also
     * accepts an existing /embed/ URL so re-saving one is a no-op) and
     * returns a privacy-enhanced (youtube-nocookie.com) embed URL. Returns
     * null for anything else (a non-YouTube host, or a URL we can't parse)
     * so the view can fall back to a plain link instead of iframing an
     * arbitrary, unverified URL.
     */
    public function youtubeEmbedUrl(): ?string
    {
        if (! $this->video_url) {
            return null;
        }

        $id = self::extractYoutubeVideoId($this->video_url);

        return $id ? "https://www.youtube-nocookie.com/embed/{$id}" : null;
    }

    public static function extractYoutubeVideoId(string $url): ?string
    {
        if (preg_match(
            '#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#i',
            $url,
            $matches
        )) {
            return $matches[1];
        }

        return null;
    }
}
