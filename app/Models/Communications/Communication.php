<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Communication Archive index row (AT-32). Channel-agnostic; raw payload on
 * disk, index here. Append-only + soft-delete; 5-yr prune is a soft event.
 */
class Communication extends Model
{
    use SoftDeletes, BelongsToAgency;

    const CHANNEL_EMAIL    = 'email';
    const CHANNEL_WHATSAPP = 'whatsapp';
    const DIRECTION_INBOUND  = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'agency_id', 'channel', 'direction', 'external_id', 'thread_key',
        'from_identifier', 'participant_identifiers', 'occurred_at', 'captured_at',
        'provisional_at', 'subject', 'body_text', 'body_preview', 'raw_path',
        'has_attachments', 'content_hash', 'text_hash', 'source_ref',
        'owner_user_id', 'purged_at', 'purged_reason',
    ];

    protected $casts = [
        'participant_identifiers' => 'array',
        'occurred_at'            => 'datetime',
        'captured_at'            => 'datetime',
        'provisional_at'         => 'datetime',
        'purged_at'              => 'datetime',
        'has_attachments'        => 'boolean',
    ];

    // ── Relationships ──

    public function attachments(): HasMany
    {
        return $this->hasMany(CommunicationAttachment::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(CommunicationLink::class);
    }

    /**
     * AT-122 — the agent whose mailbox/device this message was ingested through
     * (provenance). Nullable. The future AT-118 gate keys per-agent visibility
     * off this; nothing reads it yet.
     */
    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    // ── Scopes ──

    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeEmail($query)
    {
        return $query->where('channel', self::CHANNEL_EMAIL);
    }

    public function scopeWhatsapp($query)
    {
        return $query->where('channel', self::CHANNEL_WHATSAPP);
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeNotPurged($query)
    {
        return $query->whereNull('purged_at');
    }

    /** Provisional rows: created on click, not yet reconciled to a real send. */
    public function scopeProvisional($query)
    {
        return $query->whereNotNull('provisional_at');
    }

    /** Confirmed rows: ingested or reconciled (provisional_at cleared). */
    public function scopeConfirmed($query)
    {
        return $query->whereNull('provisional_at');
    }

    public function isProvisional(): bool
    {
        return $this->provisional_at !== null;
    }

    /** Records past the 5-year retention window (by occurred_at), not yet purged. */
    public function scopePastRetention($query, ?\DateTimeInterface $cutoff = null)
    {
        $cutoff ??= now()->subYears(5);
        return $query->whereNull('purged_at')->where('occurred_at', '<', $cutoff);
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * AT-118 — owner/scope visibility for the Communications Access Gate.
     * Mirrors the AT-120 own/branch/all pattern (OutreachQueue::scopeVisibleTo)
     * but the "owner" of a comm is the ingesting agent (owner_user_id), and
     * branch is derived from that owner's branch (communications carry no
     * branch_id of their own).
     *
     *   own     → comms this user OWNS (owner_user_id === user). This IS the
     *             default-visibility "owning agent" rule from the spec.
     *   branch  → comms owned by an agent in the user's branch (no branch → own).
     *   all     → every comm (within the agency — AgencyScope still applies).
     *   none/null → nothing.
     *
     * NULL owner_user_id (legacy/outbound provisional rows) has no owning agent,
     * so it is EXCLUDED from own + branch (never opens by accident) and only
     * visible under 'all'. The grant + grant_access tiers live in the controller
     * gate, not here — this scope is purely the owner/role-scope set.
     */
    public function scopeVisibleTo(Builder $query, User $user, ?string $scope): Builder
    {
        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->whereHas('owner', fn ($q) => $q->where('branch_id', $user->effectiveBranchId()))
                : $query->where('owner_user_id', $user->id),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->where('owner_user_id', $user->id), // 'own' or null capability
        };
    }
}
