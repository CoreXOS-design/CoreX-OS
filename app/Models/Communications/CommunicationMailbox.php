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
    ];

    protected $casts = [
        'encrypted_password' => 'encrypted',
        'poll_inbox'         => 'boolean',
        'poll_sent'          => 'boolean',
        'poll_interval_minutes' => 'integer',
        'last_polled_at'     => 'datetime',
        'last_uid_seen'      => 'integer',
        'active'             => 'boolean',
        'last_error_at'      => 'datetime',
        'consecutive_failures' => 'integer',
        'failure_notified_at' => 'datetime',
    ];

    // Health states surfaced on the mailboxes screen (AT-181). These are DERIVED — the
    // manual `active` flag is only one input; genuine ingestion health is read from
    // last_error + last_polled_at freshness.
    public const HEALTH_INACTIVE = 'inactive'; // manually switched off
    public const HEALTH_PENDING  = 'pending';  // active, never polled yet, not overdue
    public const HEALTH_HEALTHY  = 'healthy';  // active, last poll succeeded + recent
    public const HEALTH_FAILING  = 'failing';  // active, but erroring or stale/never-connected

    // Never serialised. The encrypted password is write-only from every UI/API —
    // the single sanctioned read path is the audited reveal (AT-37), which reads
    // the attribute server-side and logs the access; it never goes through
    // toArray()/toJson().
    protected $hidden = [
        'encrypted_password',
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
            'auth_failed' => 'Login failed — check the username and password',
            'incomplete_credentials' => 'Mailbox is missing host, username or password',
            'read_timeout' => 'Connected, but reading the mailbox timed out',
            default => ucfirst(str_replace('_', ' ', (string) $this->last_error)),
        };
    }
}
