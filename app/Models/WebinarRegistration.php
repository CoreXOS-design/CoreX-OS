<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person who signed up for a webinar.
 *
 * Spec: .ai/specs/webinar-registration.md §3.2
 *
 * THIS IS THE ONLY PLACE A REGISTRANT EXISTS. Johan's decision (§0 A5): webinar
 * registrants do not become Contacts. Nothing here touches the Contact pillar.
 *
 * The row outlives any single grant. Re-registering issues a FRESH access code
 * (the old one is bcrypt-only and unrecoverable, so it cannot be re-sent) and repoints
 * demo_access_grant_id at the new grant; the superseded grants stay in
 * demo_access_grants, which is the evidence trail.
 */
class WebinarRegistration extends Model
{
    /** How long before a registrant may be issued a fresh code. Spec §0 D5. */
    public const REISSUE_COOLDOWN_MINUTES = 15;

    protected $fillable = [
        'webinar_id',
        'name',
        'email',
        'company_name',
        'phone',
        'demo_access_grant_id',
        'confirmation_sent_at',
        'reminder_sent_at',
        'last_issued_at',
        'ip_address',
        'user_agent',
        'source',
    ];

    protected $casts = [
        'confirmation_sent_at' => 'datetime',
        'reminder_sent_at'     => 'datetime',
        'last_issued_at'       => 'datetime',
    ];

    /**
     * Is this registrant inside the re-issue cooldown?
     *
     * Re-registering is legitimate — someone lost the email, or is not sure the first
     * submit worked. What it must not be is a tap: one form post, one working
     * credential, and no way to loop it.
     */
    public function isWithinReissueCooldown(?Carbon $now = null): bool
    {
        if ($this->last_issued_at === null) {
            return false;
        }

        $now = $now ?: Carbon::now();

        return $this->last_issued_at->greaterThan($now->copy()->subMinutes(self::REISSUE_COOLDOWN_MINUTES));
    }

    /** Plain-English access state for the admin list — reads through to the grant. */
    public function accessStatusLabel(): string
    {
        if (! $this->grant) {
            return 'No access issued';
        }

        return $this->grant->statusLabel();
    }

    // ---- Scopes ------------------------------------------------------------

    /** Still owed their reminder. The stamp is what makes it exactly once. */
    public function scopeAwaitingReminder(Builder $q): Builder
    {
        return $q->whereNull('reminder_sent_at');
    }

    // ---- Relationships -----------------------------------------------------

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(DemoAccessGrant::class, 'demo_access_grant_id');
    }
}
