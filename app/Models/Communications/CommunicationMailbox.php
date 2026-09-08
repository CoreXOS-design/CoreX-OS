<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Email adapter config (AT-32). Agency-held IMAP credentials; password stored
 * encrypted via the 'encrypted' cast.
 */
class CommunicationMailbox extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'communication_mailboxes';

    protected $fillable = [
        'agency_id', 'user_id', 'email_address', 'imap_host', 'imap_port', 'username',
        'encrypted_password', 'auth_type', 'set_by', 'poll_inbox', 'poll_sent',
        'poll_interval_minutes', 'last_polled_at', 'last_uid_seen', 'active',
        'last_error', 'last_error_at', 'consecutive_failures', 'failure_notified_at',
        // AT-395 Phase A — outgoing (SMTP) mail fields, spec §2.
        'outgoing_enabled', 'use_imap_credentials_for_smtp', 'smtp_host', 'smtp_port',
        'smtp_encryption', 'smtp_username', 'smtp_encrypted_password', 'smtp_from_name',
        'outgoing_active', 'last_send_error', 'last_send_error_at', 'consecutive_send_failures',
        'send_failure_notified_at', 'last_sent_at', 'last_sent_folder_append_error',
        'last_sent_folder_append_at',
        // 2026-09-08 incremental-poll fix — per-folder watermark/UID cursor,
        // fairness duration tracking, one-time backfill marker.
        'inbox_watermark_at', 'inbox_uid_validity', 'sent_watermark_at',
        'sent_last_uid', 'sent_uid_validity', 'last_poll_duration_seconds',
        'backfill_completed_at',
        // 2026-09-08 (Johan, part B) — honest "Behind" health state.
        'messages_behind_estimate',
    ];

    protected $casts = [
        'encrypted_password' => 'encrypted',
        'poll_inbox'         => 'boolean',
        'poll_sent'          => 'boolean',
        'poll_interval_minutes' => 'integer',
        'last_polled_at'     => 'datetime',
        'last_uid_seen'      => 'integer',
        'sent_last_uid'      => 'integer',
        'inbox_uid_validity' => 'integer',
        'sent_uid_validity'  => 'integer',
        'active'             => 'boolean',
        'last_error_at'      => 'datetime',
        'consecutive_failures' => 'integer',
        'failure_notified_at' => 'datetime',
        'messages_behind_estimate' => 'integer',
        // AT-395 Phase A
        'outgoing_enabled'              => 'boolean',
        'use_imap_credentials_for_smtp' => 'boolean',
        'smtp_port'                     => 'integer',
        'smtp_encrypted_password'       => 'encrypted',
        'outgoing_active'               => 'boolean',
        'last_send_error_at'            => 'datetime',
        'consecutive_send_failures'     => 'integer',
        'send_failure_notified_at'      => 'datetime',
        'last_sent_at'                  => 'datetime',
        'last_sent_folder_append_at'    => 'datetime',
    ];

    // Health states surfaced on the mailboxes screen (AT-181). These are DERIVED — the
    // manual `active` flag is only one input; genuine ingestion health is read from
    // last_error + last_polled_at freshness.
    public const HEALTH_INACTIVE = 'inactive'; // manually switched off
    public const HEALTH_PENDING  = 'pending';  // active, never polled yet, not overdue
    public const HEALTH_HEALTHY  = 'healthy';  // active, last poll succeeded + recent
    public const HEALTH_FAILING  = 'failing';  // active, but erroring or stale/never-connected
    // 2026-09-08 (Johan, part B) — connected fine, just working through a
    // backlog our own time budget hasn't let it finish yet. NOT a failure:
    // auth and the read both succeeded this run. Deliberately distinct from
    // HEALTH_FAILING so the screen never shows "broken" for a mailbox that is
    // demonstrably working (see BUILD_STANDARD — a status field must say what
    // actually happened, not the closest existing label).
    public const HEALTH_BEHIND   = 'behind';

    // Never serialised. The encrypted password is write-only from every UI/API —
    // the single sanctioned read path is the audited reveal (AT-37), which reads
    // the attribute server-side and logs the access; it never goes through
    // toArray()/toJson().
    protected $hidden = [
        'encrypted_password',
        'smtp_encrypted_password',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function reveals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MailboxCredentialReveal::class, 'mailbox_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * AT-395 §7.3 — OWN / BRANCH / AGENCY visibility, via the same mechanism
     * every other personal-record module already uses (e.g. Rental.php:39-53).
     * No new scoping pattern invented.
     */
    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'communication_mailboxes');

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('user', function ($q) use ($user) {
                $q->where('branch_id', $user->effectiveBranchId());
            });
        }
        if ($scope === 'own') {
            return $query->where('user_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * AT-395 §3.1 — resolve the mailbox to send THROUGH for a given agent.
     * User match only in Phase A (no agency-wide fallback — see spec §3.1 for
     * where that would plug in later). Never returns an archived, disabled,
     * or outgoing-inactive mailbox.
     */
    public static function resolveOutgoingFor(\App\Models\User $agent): ?self
    {
        $agencyId = $agent->effectiveAgencyId();
        if (! $agencyId) {
            return null;
        }

        return static::query()
            ->where('agency_id', $agencyId)
            ->where('user_id', $agent->id)
            ->where('outgoing_enabled', true)
            ->where('outgoing_active', true)
            ->first();
    }

    /** AT-395 §2.4 — the credential actually used for sending. */
    public function resolvedSmtpUsername(): ?string
    {
        return $this->use_imap_credentials_for_smtp ? $this->username : $this->smtp_username;
    }

    /** AT-395 §2.4 — the credential actually used for sending (decrypted by the relevant cast). */
    public function resolvedSmtpPassword(): ?string
    {
        return $this->use_imap_credentials_for_smtp ? $this->encrypted_password : $this->smtp_encrypted_password;
    }

    /**
     * AT-395 §2.4 — outgoing counterpart to pollHealth(), same four states,
     * same derivation shape, reading the _send_/_sent_ columns instead.
     */
    public function sendHealth(): string
    {
        if (! $this->outgoing_enabled || ! $this->outgoing_active) {
            return self::HEALTH_INACTIVE;
        }
        if ($this->last_send_error !== null) {
            return self::HEALTH_FAILING;
        }
        if ($this->last_sent_at !== null) {
            $staleMinutes = 2 * max(1, (int) $this->poll_interval_minutes);
            return $this->last_sent_at->lt(now()->subMinutes($staleMinutes)) ? self::HEALTH_FAILING : self::HEALTH_HEALTHY;
        }

        return self::HEALTH_PENDING;
    }

    /** Plain-English label for the recorded send-failure reason (null when healthy). */
    public function lastSendErrorLabel(): ?string
    {
        return match ($this->last_send_error) {
            null => null,
            'connect_failed' => 'Could not connect to the mail server',
            'auth_failed' => 'Login failed — check the username and password',
            'incomplete_credentials' => 'Mailbox is missing an outgoing host, username or password',
            'send_rejected' => 'Connected, but the mail server refused to send the message',
            default => ucfirst(str_replace('_', ' ', (string) $this->last_send_error)),
        };
    }

    // ── Health derivation (AT-181) ────────────────────────────────────────────

    /**
     * Staleness threshold in minutes — DERIVED from the mailbox's own poll interval, never
     * hardcoded. A mailbox that has not successfully polled within ~2 intervals is stale.
     */
    public function staleThresholdMinutes(): int
    {
        return 2 * max(1, (int) $this->poll_interval_minutes);
    }

    /** Has the last successful poll gone stale (or never happened)? */
    public function isPollStale(): bool
    {
        if ($this->last_polled_at === null) {
            return true;
        }

        return $this->last_polled_at->lt(now()->subMinutes($this->staleThresholdMinutes()));
    }

    /**
     * The HONEST health state (spec AT-181): the manual `active` flag is only one input.
     *
     *  - inactive : manually switched off.
     *  - behind   : active, connected and authenticated FINE this run, but our own time
     *               budget cut the read short partway through a backlog (2026-09-08, part
     *               B). Checked BEFORE last_error/staleness on purpose: a mailbox that is
     *               genuinely working must never be reported as broken just because it
     *               hasn't finished yet.
     *  - failing  : active but the last poll errored, OR it has never connected / gone stale
     *               beyond ~2 intervals (the broken-setup signature — bad host/creds/TLS).
     *  - pending  : active, never polled yet, but not yet overdue for its first poll (a brand-new
     *               mailbox the scheduler simply has not reached — not a failure).
     *  - healthy  : active, last poll succeeded, and it is within its freshness window.
     */
    public function pollHealth(): string
    {
        if (! $this->active) {
            return self::HEALTH_INACTIVE;
        }
        if ($this->messages_behind_estimate !== null) {
            return self::HEALTH_BEHIND;
        }
        if ($this->last_error !== null) {
            return self::HEALTH_FAILING;
        }
        if ($this->last_polled_at !== null) {
            return $this->isPollStale() ? self::HEALTH_FAILING : self::HEALTH_HEALTHY;
        }
        // Never polled: pending until it is overdue for a first poll, then failing.
        $overdue = $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes($this->staleThresholdMinutes()));

        return $overdue ? self::HEALTH_FAILING : self::HEALTH_PENDING;
    }

    /** Plain-English label for the recorded failure reason (null when healthy). */
    public function lastErrorLabel(): ?string
    {
        return match ($this->last_error) {
            null => null,
            'connect_failed' => 'Could not connect to the mail server',
            'connect_timeout' => 'The mail server did not respond in time (timed out connecting)',
            'auth_failed' => 'Login failed — check the username and password',
            'incomplete_credentials' => 'Mailbox is missing host, username or password',
            'read_timeout' => 'Connected, but reading the mailbox timed out — likely a large backlog',
            default => ucfirst(str_replace('_', ' ', (string) $this->last_error)),
        };
    }

    /**
     * 2026-09-08 (Johan, part B) — Johan's own language: "Working through a backlog,
     * about N messages behind", never "cannot connect". Null when not behind (the
     * caller checks pollHealth() === HEALTH_BEHIND first).
     */
    public function behindLabel(): ?string
    {
        if ($this->messages_behind_estimate === null) {
            return null;
        }

        return $this->messages_behind_estimate > 0
            ? "Connected — working through a backlog, about {$this->messages_behind_estimate} message(s) behind."
            : 'Connected — working through a backlog, catching up now.';
    }
}
