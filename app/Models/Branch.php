<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'name',
        'code',
        'agency_id',
        'trading_name',
        'tagline',
        'address',
        'phone',
        'phone_label',
        'phone_secondary',
        'phone_secondary_label',
        'fax',
        'email',
        'reg_no',
        'vat_no',
        'ffc_no',
        'ppra_number',
        'fic_no',
        'logo_path',
        'p24_agency_id',
        'syndication_override_enabled',
        'pp_agency_id',
        'pp_credentials',
        'p24_credentials',
        'privacy_policy_markdown',
        'privacy_policy_token',
        'privacy_policy_published_at',
        'website_order',
    ];

    protected $casts = [
        'syndication_override_enabled' => 'boolean',
        'pp_credentials'  => 'encrypted:array',
        'p24_credentials' => 'encrypted:array',
        'privacy_policy_published_at' => 'datetime',
        'website_order'   => 'integer',
    ];

    /**
     * Returns contact detail value — branch value if set,
     * otherwise falls back to Agency value.
     */
    public function contactDetail(string $field): ?string
    {
        return $this->{$field} ?? $this->agency->{$field} ?? null;
    }

    // ── Privacy Policy (per-branch override) ──

    /** Branch markdown if set, else inherit from agency. */
    public function effectivePrivacyPolicyMarkdown(): ?string
    {
        return $this->privacy_policy_markdown ?: ($this->agency?->privacy_policy_markdown ?: null);
    }

    /**
     * Resolution helper that decides which (token, published_at) pair
     * to honour for this branch context. If the branch has its own
     * token, use it together with the branch's published flag. Else
     * fall back to the agency's pair. Returns [token, publishedAt] or
     * [null, null] when nothing is configured.
     *
     * @return array{0: ?string, 1: ?\Carbon\CarbonInterface}
     */
    private function resolvePolicyTokenAndPublishedAt(): array
    {
        if ($this->privacy_policy_token) {
            return [$this->privacy_policy_token, $this->privacy_policy_published_at];
        }
        $agency = $this->agency;
        if ($agency && $agency->privacy_policy_token) {
            return [$agency->privacy_policy_token, $agency->privacy_policy_published_at];
        }
        return [null, null];
    }

    public function effectivePrivacyPolicyToken(): ?string
    {
        return $this->resolvePolicyTokenAndPublishedAt()[0];
    }

    public function effectivePrivacyPolicyUrl(): ?string
    {
        [$token, $publishedAt] = $this->resolvePolicyTokenAndPublishedAt();
        if (!$token || !$publishedAt) {
            return null;
        }
        return route('public.privacy-policy', ['token' => $token]);
    }

    /**
     * Cascade: internal published URL (branch or agency) > external popi_url
     * (branch falls back to agency for that too) > null.
     */
    public function effectivePopiUrl(): ?string
    {
        return $this->effectivePrivacyPolicyUrl()
            ?: ($this->agency?->effectivePopiUrl() ?: null);
    }

    /**
     * Resolve the Property24 agency ID for this branch: branch override,
     * else parent agency's default. Null = neither configured.
     */
    public function resolveP24AgencyId(): ?string
    {
        if (!empty($this->p24_agency_id)) {
            return (string) $this->p24_agency_id;
        }
        $parent = $this->agency?->p24_agency_id;
        return $parent !== null && $parent !== '' ? (string) $parent : null;
    }

    /**
     * Resolve PP SOAP credentials for listings owned by this branch.
     * Returns the branch's credentials only when syndication_override_enabled
     * is on AND credentials are populated; otherwise null = caller falls
     * back to the agency-level / env-level credentials it was using before.
     *
     * @return array<string,mixed>|null
     */
    public function resolvePpCredentials(): ?array
    {
        if (!$this->syndication_override_enabled) {
            return null;
        }
        return is_array($this->pp_credentials) && !empty($this->pp_credentials)
            ? $this->pp_credentials
            : null;
    }

    /**
     * Resolve P24 API credentials for listings owned by this branch.
     * Same override semantics as PP.
     *
     * @return array<string,mixed>|null
     */
    public function resolveP24Credentials(): ?array
    {
        if (!$this->syndication_override_enabled) {
            return null;
        }
        return is_array($this->p24_credentials) && !empty($this->p24_credentials)
            ? $this->p24_credentials
            : null;
    }

    /**
     * Branch-level PP agency ID override. Falls back to agency's if the
     * branch has not configured one. Null = neither configured.
     */
    public function resolvePpAgencyId(): ?string
    {
        if ($this->syndication_override_enabled && !empty($this->pp_agency_id)) {
            return (string) $this->pp_agency_id;
        }
        // The Agency model currently has no pp_agency_id column; fall back
        // to env-level PP config via the SOAP client. Return null to let
        // the caller use its existing resolution path.
        return null;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
