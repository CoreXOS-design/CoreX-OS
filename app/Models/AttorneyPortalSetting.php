<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/attorney-portal.md §5 - one row per agency, read-time defaults (a null column resolves to DEFAULT_*;
 * nothing is written until the agency changes a value). The portal is OFF until an agency turns it on: it
 * exposes deal papers outside the agency, so it is a deliberate switch, not an out-of-the-box one.
 */
class AttorneyPortalSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_ENABLED = false;
    public const DEFAULT_ACCESS_GRACE_DAYS = 30;
    public const DEFAULT_LINK_VALID_DAYS = 30;

    protected $table = 'attorney_portal_settings';

    protected $fillable = ['agency_id', 'attorney_portal_enabled', 'access_grace_days', 'link_valid_days'];

    protected $casts = [
        'attorney_portal_enabled' => 'boolean',
        'access_grace_days'       => 'integer',
        'link_valid_days'         => 'integer',
    ];

    /** The row for an agency (unsaved defaults object when none). Explicit agency, no signed-in user needed. */
    public static function forAgency(int $agencyId): self
    {
        return static::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->first()
            ?? new static(['agency_id' => $agencyId]);
    }

    public function isEnabled(): bool
    {
        return $this->attorney_portal_enabled ?? self::DEFAULT_ENABLED;
    }

    public function graceDays(): int
    {
        return max(0, (int) ($this->access_grace_days ?? self::DEFAULT_ACCESS_GRACE_DAYS));
    }

    public function linkValidDays(): int
    {
        return max(1, (int) ($this->link_valid_days ?? self::DEFAULT_LINK_VALID_DAYS));
    }
}
