<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/rental-portal-access.md §7 — AT-445. One row per agency,
 * read-time default pattern (matches RentalWorkOrderSetting): a null
 * column resolves to the DEFAULT_* constant, nothing written until the
 * agency actually changes a value. All defaults are ON — per the spec,
 * this stage's whole point is that the portal works out of the box; an
 * agency that genuinely doesn't want it switches it off.
 */
class RentalPortalSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_TENANT_PORTAL_ENABLED = true;
    public const DEFAULT_LANDLORD_PORTAL_ENABLED = true;
    public const DEFAULT_CONTRACTOR_LINKS_ENABLED = true;
    public const DEFAULT_CONTRACTOR_SECURE_LINK_EXPIRY_DAYS = 14;
    public const DEFAULT_NOTIFY_LANDLORD_ON_DECISION_NEEDED = true;
    public const DEFAULT_NOTIFY_TENANT_ON_STATUS_CHANGE = true;

    protected $fillable = [
        'agency_id',
        'tenant_portal_enabled',
        'landlord_portal_enabled',
        'contractor_links_enabled',
        'contractor_secure_link_expiry_days',
        'notify_landlord_on_decision_needed',
        'notify_tenant_on_status_change',
    ];

    protected $casts = [
        'tenant_portal_enabled' => 'boolean',
        'landlord_portal_enabled' => 'boolean',
        'contractor_links_enabled' => 'boolean',
        'contractor_secure_link_expiry_days' => 'integer',
        'notify_landlord_on_decision_needed' => 'boolean',
        'notify_tenant_on_status_change' => 'boolean',
    ];

    private static function boolFor(?int $agencyId, string $column, bool $default): bool
    {
        if (!$agencyId) {
            return $default;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value($column);

        return $value !== null ? (bool) $value : $default;
    }

    public static function tenantPortalEnabledFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'tenant_portal_enabled', self::DEFAULT_TENANT_PORTAL_ENABLED);
    }

    public static function landlordPortalEnabledFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'landlord_portal_enabled', self::DEFAULT_LANDLORD_PORTAL_ENABLED);
    }

    public static function contractorLinksEnabledFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'contractor_links_enabled', self::DEFAULT_CONTRACTOR_LINKS_ENABLED);
    }

    public static function contractorSecureLinkExpiryDaysFor(?int $agencyId): int
    {
        if (!$agencyId) {
            return self::DEFAULT_CONTRACTOR_SECURE_LINK_EXPIRY_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('contractor_secure_link_expiry_days');

        return $value !== null ? (int) $value : self::DEFAULT_CONTRACTOR_SECURE_LINK_EXPIRY_DAYS;
    }

    public static function notifyLandlordOnDecisionNeededFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'notify_landlord_on_decision_needed', self::DEFAULT_NOTIFY_LANDLORD_ON_DECISION_NEEDED);
    }

    public static function notifyTenantOnStatusChangeFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'notify_tenant_on_status_change', self::DEFAULT_NOTIFY_TENANT_ON_STATUS_CHANGE);
    }
}
