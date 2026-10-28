<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/attorney-portal.md §3 - ONE attorney contact at ONE firm. Only the SHA-256 of the emailed link
 * token is stored. A resend rotates the token on this same row (the old link dies at once).
 */
class AttorneyPortalAccess extends Model
{
    use BelongsToAgency;

    protected $table = 'attorney_portal_accesses';

    protected $fillable = [
        'agency_id', 'service_provider_id', 'service_provider_contact_id', 'email', 'token_hash',
        'invited_by_id', 'invited_at', 'expires_at', 'revoked_at', 'revoked_by_id',
        'last_login_at', 'last_login_ip', 'login_count',
    ];

    protected $casts = [
        'invited_at'    => 'datetime',
        'expires_at'    => 'datetime',
        'revoked_at'    => 'datetime',
        'last_login_at' => 'datetime',
        'login_count'   => 'integer',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function supplierContact(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProviderContact::class, 'service_provider_contact_id')->withoutGlobalScopes();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'service_provider_id')->withoutGlobalScopes();
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id')->withoutGlobalScopes();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AttorneyPortalSession::class, 'access_id');
    }

    /** Link usable now: not revoked, not past its expiry. (The agency switch is checked separately by the service.) */
    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** "live" | "expired" | "revoked" - for the agent's panel. */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->isLive() ? 'live' : 'expired';
    }

    /** The person's name as the portal greets them. */
    public function displayName(): string
    {
        $c = $this->supplierContact;

        return trim((string) ($c?->attorney_name ?: $c?->contact_person ?: $this->firm?->name)) ?: 'there';
    }
}
