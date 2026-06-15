<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user progress for an interactive help tour.
 *
 * @see \App\Support\Tours\TourRegistry  for the tour definitions (the data).
 * @see \App\Http\Controllers\TourProgressController  for the seen/dismiss writes.
 */
class UserTourProgress extends Model
{
    protected $table = 'user_tour_progress';

    protected $fillable = [
        'user_id',
        'tour_key',
        'completed_at',
        'dismissed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A tour should auto-launch only if the user has neither completed nor
     * dismissed it. Manual relaunch from the "?" launcher always bypasses this.
     */
    public function suppressesAutoStart(): bool
    {
        return $this->completed_at !== null || $this->dismissed_at !== null;
    }
}
