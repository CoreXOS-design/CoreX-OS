<?php

namespace App\Models\DealV2;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pipeline Dashboard Phase 1 — a user's default pipeline view (timeline | list). One row per user,
 * mirroring CalendarUserPreference. Either view can still switch live; this is only the landing view.
 * Spec: .ai/specs/pipeline-dashboard.md §3.4
 */
class PipelineUserPreference extends Model
{
    public const VIEWS = ['timeline', 'list'];

    protected $fillable = [
        'user_id',
        'default_view',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The user's chosen default view, or the system default ('timeline') when unset. */
    public static function viewForUser(int $userId): string
    {
        $view = static::query()->where('user_id', $userId)->value('default_view');
        return in_array($view, self::VIEWS, true) ? $view : 'timeline';
    }

    /** Persist a user's default view (idempotent upsert). Invalid values are ignored. */
    public static function setViewForUser(int $userId, string $view): void
    {
        if (! in_array($view, self::VIEWS, true)) {
            return;
        }
        static::query()->updateOrCreate(['user_id' => $userId], ['default_view' => $view]);
    }
}
