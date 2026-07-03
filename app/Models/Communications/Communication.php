<?php

namespace App\Models\Communications;

use App\Models\Communications\CommunicationAttachment;
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
        'agency_id', 'channel', 'direction', 'external_id', 'thread_key', 'wa_chat_id', 'counterpart_lid',
        'from_identifier', 'participant_identifiers', 'occurred_at', 'captured_at',
        'provisional_at', 'subject', 'body_text', 'body_preview', 'body_status', 'raw_path',
        'has_attachments', 'content_hash', 'text_hash', 'source_ref',
        'owner_user_id', 'purged_at', 'purged_reason',
        // AT-163 — voice-note transcript (rides the message row, 1:1).
        'transcript_text', 'transcript_preview', 'transcript_status', 'transcript_retry_count',
        'transcript_lang', 'transcript_model', 'transcript_error', 'transcript_at',
    ];

    protected $casts = [
        'participant_identifiers' => 'array',
        'occurred_at'            => 'datetime',
        'captured_at'            => 'datetime',
        'provisional_at'         => 'datetime',
        'purged_at'              => 'datetime',
        'has_attachments'        => 'boolean',
        'transcript_at'          => 'datetime',
    ];

    /** AT-163 — a completed, non-empty voice-note transcript is present. */
    public function hasTranscript(): bool
    {
        return $this->transcript_status === 'done' && filled($this->transcript_text);
    }

    /**
     * AT-163 — messages that still need transcription: a WhatsApp note with a
     * consent-captured (not withheld) stored audio attachment, not yet done.
     * 'failed' is terminal (retry-capped) and excluded from the batch — a manual
     * Retry re-dispatches it explicitly.
     */
    public function scopeNeedsTranscription($query)
    {
        return $query
            ->where('channel', self::CHANNEL_WHATSAPP)
            ->whereNull('purged_at')
            // NOT a withheld body. A media-only note has body_status NULL — must be
            // INCLUDED (SQL `NULL NOT IN (…)` is not true, so whereNotIn would drop it).
            ->where(function ($q) {
                $q->whereNull('body_status')
                  ->orWhereNotIn('body_status', ['embargoed', 'consent_pending', 'embargo_purged']);
            })
            ->where(function ($q) {
                $q->whereNull('transcript_status')->orWhere('transcript_status', 'pending');
            })
            ->whereHas('attachments', function ($a) {
                $a->where('mime', 'like', 'audio%')
                  ->where('media_status', CommunicationAttachment::MEDIA_STORED)
                  ->whereNotNull('storage_path');
            });
    }

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

    /**
     * AT-137 — direction-aware "from" for archive/thread display. An audit record
     * must show WHO sent each message: on OUTBOUND that is the AGENT (the owner
     * whose device/mailbox sent it), on INBOUND it is the counterpart
     * (from_identifier). Previously every row showed from_identifier (the contact)
     * in both directions. Eager-load `owner` to avoid N+1.
     */
    public function getFromDisplayAttribute(): string
    {
        if ($this->direction === self::DIRECTION_OUTBOUND) {
            return $this->owner?->name ?: 'Agent';
        }

        return $this->from_identifier ?: '—';
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
        // 'all' sees everything in the agency; 'none' sees nothing — both unchanged.
        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'none') {
            return $query->whereRaw('1 = 0');
        }

        // AT-118 — multi-participant visibility. Beyond the scope tier (own =
        // owner_user_id; branch = owner in my branch), a user ALSO sees any thread
        // they were genuinely on — i.e. one of their OWN active mailbox addresses
        // is in participant_identifiers (the deduped to/from/cc set). This closes
        // the dual-recipient gap: an email to two agents ingests once under a
        // single owner_user_id, but both recipients were on it and both should see
        // it without requesting. Applied to own AND branch (the demonstrated case
        // is a branch_manager whose branch scope doesn't cover an out-of-branch
        // owner). Participant→agent maps ONLY via communication_mailboxes — never
        // contact_emails (those map to contacts, not agents).
        $mailboxAddresses  = static::participantMailboxAddresses($user);
        $grantedThreadKeys = static::grantedThreadKeysFor($user);
        $grantedCommIds    = static::grantedCommIdsFor($user);

        return $query->where(function (Builder $outer) use ($user, $scope, $mailboxAddresses, $grantedThreadKeys, $grantedCommIds) {
            // (a) scope tier
            if ($scope === 'branch' && $user->effectiveBranchId()) {
                $outer->whereHas('owner', fn ($q) => $q->where('branch_id', $user->effectiveBranchId()));
            } else {
                $outer->where('owner_user_id', $user->id); // 'own', or 'branch' with no branch id
            }

            // (b) OR — participant visibility, THREAD-LEVEL (AT-127). A user who was
            // on ANY message of a thread (via their own active mailbox) sees EVERY
            // message in that thread — closing the reply-vs-reply-all gap (a colleague
            // who replies without reply-all must not hide that message from the other
            // thread agent). Mailbox-less users add nothing here.
            if (!empty($mailboxAddresses)) {
                $outer->orWhere(function (Builder $p) use ($mailboxAddresses) {
                    // (b1) per-message match — also the ONLY path for a NULL/empty
                    // thread_key comm (it matches itself, never groups with other
                    // null-thread comms).
                    foreach ($mailboxAddresses as $addr) {
                        $p->orWhereRaw('JSON_CONTAINS(participant_identifiers, ?)', [json_encode($addr)]);
                    }

                    // (b2) thread-level — this comm's (non-empty) thread_key is one
                    // where I was a participant on at least one message. The subquery
                    // is Eloquent so AgencyScope + SoftDeletes apply (multi-tenant safe).
                    $threadKeys = static::query()
                        ->select('thread_key')
                        ->whereNotNull('thread_key')->where('thread_key', '!=', '')
                        ->whereNull('purged_at')
                        ->where(function (Builder $w) use ($mailboxAddresses) {
                            foreach ($mailboxAddresses as $addr) {
                                $w->orWhereRaw('JSON_CONTAINS(participant_identifiers, ?)', [json_encode($addr)]);
                            }
                        });

                    $p->orWhere(function (Builder $t) use ($threadKeys) {
                        $t->whereNotNull('thread_key')->where('thread_key', '!=', '')
                          ->whereIn('thread_key', $threadKeys);
                    });
                });
            }

            // (c) OR — AT-132 per-thread / per-comm GRANT. The user holds a live
            // grant (session OR always) for this comm's thread, or — for a
            // null-thread comm — this exact comm. Granted threads match by
            // thread_key (non-empty only); granted null-thread comms match by id.
            // NULL/empty thread_key comms are reachable ONLY via the comm-id set,
            // never via thread_key grouping (same isolation as AT-127's b1).
            // Legacy whole-contact grants (thread_key+comm both null) are NOT here —
            // they grant whole-contact access via the controller's hasActiveGrant
            // path, not this row scope.
            if (!empty($grantedThreadKeys) || !empty($grantedCommIds)) {
                $outer->orWhere(function (Builder $g) use ($grantedThreadKeys, $grantedCommIds) {
                    if (!empty($grantedThreadKeys)) {
                        $g->where(function (Builder $t) use ($grantedThreadKeys) {
                            $t->whereNotNull('thread_key')->where('thread_key', '!=', '')
                              ->whereIn('thread_key', $grantedThreadKeys);
                        });
                    }
                    if (!empty($grantedCommIds)) {
                        $g->orWhereIn('id', $grantedCommIds);
                    }
                });
            }
        });
    }

    /**
     * AT-118 — the current user's OWN active mailbox addresses, normalised
     * (lower/trim) to match how participant_identifiers are stored. Used to let an
     * agent who was on an email (to/from/cc) see it without requesting. Maps via
     * communication_mailboxes only (agent-owned accounts), not contact identifiers.
     */
    protected static function participantMailboxAddresses(User $user): array
    {
        $rows = CommunicationMailbox::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->get(['username', 'email_address']);

        $addrs = [];
        foreach ($rows as $row) {
            foreach ([$row->username, $row->email_address] as $a) {
                $a = strtolower(trim((string) $a));
                if ($a !== '' && str_contains($a, '@')) {
                    $addrs[$a] = true;
                }
            }
        }

        return array_keys($addrs);
    }

    /**
     * AT-132 — thread_keys the user holds a LIVE grant for (session or always).
     * Drives the per-thread grant branch of scopeVisibleTo. Excludes legacy
     * whole-contact grants (thread_key null) and null-thread comm grants (those
     * carry communication_id, handled by grantedCommIdsFor). AgencyScope +
     * SoftDeletes apply via the CommsAccessRequest model (multi-tenant safe).
     */
    protected static function grantedThreadKeysFor(User $user): array
    {
        return CommsAccessRequest::query()
            ->byRequester($user->id)
            ->liveGrant()
            ->whereNotNull('thread_key')->where('thread_key', '!=', '')
            ->pluck('thread_key')
            ->unique()->values()->all();
    }

    /**
     * AT-132 — communication ids the user holds a LIVE grant for (null-thread
     * comms keyed on the comm itself, never grouped). Drives the per-comm branch
     * of scopeVisibleTo.
     */
    protected static function grantedCommIdsFor(User $user): array
    {
        return CommsAccessRequest::query()
            ->byRequester($user->id)
            ->liveGrant()
            ->whereNull('thread_key')
            ->whereNotNull('communication_id')
            ->pluck('communication_id')
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
    }
}
