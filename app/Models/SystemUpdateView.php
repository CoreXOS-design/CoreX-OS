<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user acknowledgement of a system update.
 *
 * Spec: .ai/specs/system-updates.md §4.2.
 *
 * Personal UI state, keyed by user_id — not tenant-owned, so no agency_id and no
 * BelongsToAgency (same treatment and justification as UserTourProgress).
 *
 * No SoftDeletes by design: re-notify uses the SystemUpdate::$notify_reset_at
 * watermark rather than deleting rows, so a row here is never destroyed.
 */
class SystemUpdateView extends Model
{
    protected $fillable = [
        'system_update_id',
        'user_id',
        'dismissed_at',
    ];

    protected $casts = [
        'dismissed_at' => 'datetime',
    ];

    public function systemUpdate(): BelongsTo
    {
        return $this->belongsTo(SystemUpdate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
