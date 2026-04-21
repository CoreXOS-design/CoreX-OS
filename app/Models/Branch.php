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
        'fic_no',
        'logo_path',
        'p24_agency_id',
        'syndication_override_enabled',
        'pp_agency_id',
        'pp_credentials',
        'p24_credentials',
    ];

    protected $casts = [
        'syndication_override_enabled' => 'boolean',
        'pp_credentials'  => 'encrypted:array',
        'p24_credentials' => 'encrypted:array',
    ];

    /**
     * Returns contact detail value — branch value if set,
     * otherwise falls back to Agency value.
     */
    public function contactDetail(string $field): ?string
    {
        return $this->{$field} ?? $this->agency->{$field} ?? null;
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
