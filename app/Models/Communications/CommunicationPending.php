<?php

namespace App\Models\Communications;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Inbound grace buffer (AT-32, spec §7.5). Unmatched inbound communications
 * park here until a matching contact appears (retroactive attach) or the grace
 * window expires (prune).
 */
class CommunicationPending extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'communication_pending';

    /** Grace window (calendar days) before an unmatched inbound item prunes. */
    const DEFAULT_GRACE_DAYS = 4;
    const MAX_GRACE_DAYS     = 5;

    protected $fillable = [
        'agency_id', 'channel', 'direction', 'external_id', 'thread_key',
        'from_identifier', 'participant_identifiers', 'occurred_at', 'captured_at',
        'subject', 'body_text', 'body_preview', 'raw_path', 'has_attachments',
        'content_hash', 'source_ref', 'expires_at', 'nudged_at',
        'purged_at', 'purged_reason',
    ];

    protected $casts = [
        'participant_identifiers' => 'array',
        'occurred_at'            => 'datetime',
        'captured_at'            => 'datetime',
        'expires_at'             => 'datetime',
        'nudged_at'              => 'datetime',
        'purged_at'              => 'datetime',
        'has_attachments'        => 'boolean',
    ];

    // ── Scopes ──

    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', Communication::DIRECTION_INBOUND);
    }

    /** Live (not yet purged) pending items. */
    public function scopeLive($query)
    {
        return $query->whereNull('purged_at');
    }

    /** Past the grace window and still unmatched (not yet purged). */
    public function scopeExpired($query, ?\DateTimeInterface $at = null)
    {
        $at ??= now();
        return $query->whereNull('purged_at')->where('expires_at', '<=', $at);
    }

    /** Nearing expiry within $hours, not yet nudged. */
    public function scopeNearingExpiry($query, int $hours = 24)
    {
        return $query->whereNull('purged_at')->whereNull('nudged_at')
            ->whereBetween('expires_at', [now(), now()->addHours($hours)]);
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * Clamp the grace window to [1, MAX_GRACE_DAYS]. Defaults to
     * DEFAULT_GRACE_DAYS; an agency override hook can be added later without
     * touching callers.
     */
    public static function graceDays(?Agency $agency = null): int
    {
        $days = (int) ($agency->communication_pending_grace_days
            ?? config('communications.pending_grace_days', self::DEFAULT_GRACE_DAYS));

        return max(1, min(self::MAX_GRACE_DAYS, $days ?: self::DEFAULT_GRACE_DAYS));
    }
}
