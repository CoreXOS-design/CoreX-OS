<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-451 — one decision about a user who has every alert switched off. APPEND-ONLY: a row is written once and
 * never edited (the soft-delete column exists per the standard, but nothing archives these rows).
 */
class UserAlertDecision extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const ACCEPTED_OFF = 'accepted_off';
    public const TURNED_ON = 'turned_on';

    protected $fillable = [
        'agency_id', 'user_id', 'decided_by_user_id', 'decision',
        'switch_in_app', 'switch_email', 'switch_push', 'settings_stamp',
    ];

    protected $casts = [
        'switch_in_app' => 'boolean',
        'switch_email' => 'boolean',
        'switch_push' => 'boolean',
        'settings_stamp' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only: a recorded decision is history, not a draft.
        static::updating(function (self $row) {
            $dirty = array_keys($row->getDirty());
            if ($dirty !== [] && array_diff($dirty, ['deleted_at', 'updated_at']) !== []) {
                throw new \LogicException('A recorded alert decision cannot be edited.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** True when the person who decided is the user themselves. */
    public function decidedByUser(): bool
    {
        return $this->decided_by_user_id === null || (int) $this->decided_by_user_id === (int) $this->user_id;
    }
}
