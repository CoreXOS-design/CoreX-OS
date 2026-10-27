<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CoreX Desk - one in-app screen-share session (spec .ai/specs/corex-desk.md). The permanent audit record: who asked, which
 * support user joined, when, how it ended. Same shape of audit as ImpersonationLog, plus soft deletes. The 6-digit code is not
 * here - it is hashed in the canonical `otps` store (otp_id).
 */
class DeskSession extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const WAITING = 'waiting';
    public const LIVE = 'live';
    public const ENDED = 'ended';

    public const CONTROL_NONE = 'none';
    public const CONTROL_REQUESTED = 'requested';
    public const CONTROL_GRANTED = 'granted';

    public const ENDED_BY_AGENT = 'agent';
    public const ENDED_BY_SUPPORT = 'support';
    public const ENDED_BY_LOGOUT = 'logout';
    public const ENDED_BY_TIMEOUT = 'timeout';
    public const ENDED_BY_EXPIRED = 'expired';
    public const ENDED_BY_DISABLED = 'disabled';
    public const ENDED_BY_REPLACED = 'replaced';

    protected $fillable = [
        'agency_id', 'user_id', 'support_user_id', 'status', 'otp_id', 'code_expires_at', 'started_at', 'joined_at', 'ended_at',
        'ended_by', 'ended_by_user_id', 'page_url_last', 'last_heartbeat_at', 'leaving_at', 'ip_address', 'user_agent',
        'support_user_ids', 'control_support_user_id', 'control_state', 'control_tab', 'control_requested_at', 'control_granted_at', 'control_last_action_at',
    ];

    protected $casts = [
        'code_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'joined_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'leaving_at' => 'datetime',
        'support_user_ids' => 'array',
        'control_requested_at' => 'datetime',
        'control_granted_at' => 'datetime',
        'control_last_action_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }

    public function supportUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_user_id')->withoutGlobalScopes();
    }

    public function endedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id')->withoutGlobalScopes();
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScopes();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeskSessionEvent::class)->orderBy('id');
    }

    /** The support person who joined first, or anyone who attached later (the session can be re-opened and shared). */
    public function isSupporter(User $user): bool
    {
        return (int) $this->support_user_id === (int) $user->id
            || in_array((int) $user->id, array_map('intval', (array) $this->support_user_ids), true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::WAITING, self::LIVE]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::WAITING, self::LIVE], true);
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }

    /** Whole seconds the session has run (to now while it is open). */
    public function durationSeconds(): ?int
    {
        $from = $this->joined_at ?? $this->started_at;
        if (! $from) {
            return null;
        }

        return (int) max(0, $from->diffInSeconds($this->ended_at ?? now()));
    }

    /** The shape both browsers get (never the code, never anything from the screen). */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'agent_name' => $this->agent?->name,
            'agency_name' => $this->agency?->name,
            'support_name' => $this->supportUser?->name,
            'page' => $this->page_url_last,
            'started_at' => $this->started_at?->toIso8601String(),
            'joined_at' => $this->joined_at?->toIso8601String(),
            'code_expires_at' => $this->code_expires_at?->toIso8601String(),
            'control' => ['state' => $this->control_state ?: self::CONTROL_NONE, 'tab' => $this->control_tab],
        ];
    }
}
