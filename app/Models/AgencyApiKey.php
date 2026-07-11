<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Agency Public API — per-agency website API credential.
 *
 * ONE public API surface, MANY keys (one per website). The key authenticates
 * an external agency website, carries its own scopes + webhook target, and its
 * `name` is the label used for that website's Syndication Portal.
 *
 * Implements Authenticatable so the `auth:agency-api` guard (Phase 1b) can set
 * the resolved key as the request principal; because it exposes
 * effectiveAgencyId() (and is NOT an owner role), the existing global
 * AgencyScope then filters every query to this key's agency automatically.
 *
 * The full secret is shown exactly once at creation; only its sha256 hash is
 * stored. Token format presented to the website: "<key_prefix>.<secret>".
 *
 * Spec: .ai/specs/agency-public-api.md §3.1, §3.4, §3.5
 */
class AgencyApiKey extends Model implements Authenticatable
{
    use BelongsToAgency;
    use SoftDeletes;
    use AuthenticatableTrait;

    /** Available scopes (v1 — all read/receive, no public writes). */
    public const SCOPE_LISTINGS_READ   = 'listings:read';
    public const SCOPE_AGENTS_READ     = 'agents:read';
    public const SCOPE_AGENCY_READ     = 'agency:read';
    public const SCOPE_BRANCHES_READ   = 'branches:read';
    public const SCOPE_TESTIMONIALS_READ = 'testimonials:read';
    public const SCOPE_ARTICLES_READ     = 'articles:read';
    public const SCOPE_LEADS_WRITE       = 'leads:write';
    public const SCOPE_WEBHOOKS_RECEIVE = 'webhooks:receive';

    /**
     * Demo Access Control (AT-230) — the demo instance's key into primary.
     * Spec: .ai/specs/demo-access-control.md §5
     *
     * Split into two so they can be granted independently: a key that can only
     * write telemetry cannot mint or validate access. Only the demo host's key
     * carries these.
     */
    public const SCOPE_DEMO_GATE      = 'demo:gate';
    public const SCOPE_DEMO_TELEMETRY = 'demo:telemetry';

    public const SCOPES = [
        self::SCOPE_LISTINGS_READ      => 'Read listings',
        self::SCOPE_AGENTS_READ        => 'Read agent profiles',
        self::SCOPE_AGENCY_READ        => 'Read agency branding & settings',
        self::SCOPE_BRANCHES_READ      => 'Read branches (offices) & their agents',
        self::SCOPE_TESTIMONIALS_READ  => 'Read published testimonials',
        self::SCOPE_ARTICLES_READ      => 'Read published agent articles',
        self::SCOPE_LEADS_WRITE        => 'Submit website leads (enquiries)',
        self::SCOPE_WEBHOOKS_RECEIVE   => 'Receive webhook events',
        self::SCOPE_DEMO_GATE          => 'Demo: verify access grants & sessions',
        self::SCOPE_DEMO_TELEMETRY     => 'Demo: record sessions & page views',
    ];

    protected $fillable = [
        'agency_id',
        'name',
        'key_prefix',
        'secret_hash',
        'scopes',
        'webhook_url',
        'webhook_secret',
        'rate_limit_per_min',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'scopes'             => 'array',
        'webhook_secret'     => 'encrypted',
        'rate_limit_per_min' => 'integer',
        'last_used_at'       => 'datetime',
        'expires_at'         => 'datetime',
        'revoked_at'         => 'datetime',
    ];

    /** Never expose the secret hash or webhook secret in array/JSON output. */
    protected $hidden = [
        'secret_hash',
        'webhook_secret',
    ];

    // ---- Relationships -----------------------------------------------------

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(AgencyWebhookDelivery::class);
    }

    public function websiteSyndication(): HasMany
    {
        return $this->hasMany(PropertyWebsiteSyndication::class);
    }

    // ---- Tenancy / auth principal -----------------------------------------

    /**
     * Used by AgencyScope to resolve the tenant for website-API requests.
     * Mirrors User::effectiveAgencyId() so the same scope applies.
     */
    public function effectiveAgencyId(): ?int
    {
        return $this->agency_id ? (int) $this->agency_id : null;
    }

    // ---- Secret minting / verification ------------------------------------

    /**
     * Mint a fresh credential. Returns the plaintext token (shown ONCE) and the
     * pieces to persist. Caller stores key_prefix + secret_hash; the plaintext
     * is never stored.
     *
     * @return array{prefix:string, secret:string, hash:string, plaintext:string}
     */
    public static function mintSecret(bool $sandbox = false): array
    {
        $env    = $sandbox ? 'test' : 'live';
        $prefix = 'cx_' . $env . '_' . Str::lower(Str::random(8));
        $secret = Str::random(48);

        return [
            'prefix'    => $prefix,
            'secret'    => $secret,
            'hash'      => hash('sha256', $secret),
            'plaintext' => $prefix . '.' . $secret,
        ];
    }

    /** Constant-time check of a presented secret part against the stored hash. */
    public function verifySecret(string $secret): bool
    {
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    // ---- State -------------------------------------------------------------

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function statusLabel(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }
        if ($this->isExpired()) {
            return 'expired';
        }
        return 'active';
    }

    public function markUsed(): void
    {
        // Throttle the write to at most once per minute to avoid hammering the
        // row on every request.
        if ($this->last_used_at === null || $this->last_used_at->lt(Carbon::now()->subMinute())) {
            $this->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();
        }
    }
}
