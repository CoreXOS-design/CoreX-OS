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

    // .ai/specs/rental-work-orders.md §14.27.3 — Build 2 (crew page + client visibility).
    public const CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED = 'in_progress_and_completed';
    public const CREW_PHOTOS_COMPLETED_ONLY = 'completed_only';
    public const CREW_PHOTO_VISIBILITY_OPTIONS = [self::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED, self::CREW_PHOTOS_COMPLETED_ONLY];
    public const DEFAULT_CREW_PHOTOS_VISIBLE_TO_CLIENTS = self::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED;
    /** null = the crew page link stands until it is revoked. */
    public const DEFAULT_CREW_STANDING_LINK_EXPIRY_DAYS = null;
    public const DEFAULT_CREW_PAGE_RECENT_COMPLETED_DAYS = 7;
    public const DEFAULT_CREW_PAGE_UPCOMING_DAYS = 14;

    protected $fillable = [
        'agency_id',
        'tenant_portal_enabled',
        'landlord_portal_enabled',
        'contractor_links_enabled',
        'contractor_secure_link_expiry_days',
        'notify_landlord_on_decision_needed',
        'notify_tenant_on_status_change',
        'crew_photos_visible_to_clients',
        'crew_standing_link_expiry_days',
        'crew_page_recent_completed_days',
        'crew_page_upcoming_days',
    ];

    protected $casts = [
        'tenant_portal_enabled' => 'boolean',
        'landlord_portal_enabled' => 'boolean',
        'contractor_links_enabled' => 'boolean',
        'contractor_secure_link_expiry_days' => 'integer',
        'notify_landlord_on_decision_needed' => 'boolean',
        'notify_tenant_on_status_change' => 'boolean',
        'crew_standing_link_expiry_days' => 'integer',
        'crew_page_recent_completed_days' => 'integer',
        'crew_page_upcoming_days' => 'integer',
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

    // ── §14.27.3 Build 2 accessors ──────────────────────────────────────

    /** 'in_progress_and_completed' (default) | 'completed_only' — an unknown stored value falls back to the default. */
    public static function crewPhotosVisibleToClientsFor(?int $agencyId): string
    {
        if (!$agencyId) {
            return self::DEFAULT_CREW_PHOTOS_VISIBLE_TO_CLIENTS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('crew_photos_visible_to_clients');

        return in_array($value, self::CREW_PHOTO_VISIBILITY_OPTIONS, true) ? $value : self::DEFAULT_CREW_PHOTOS_VISIBLE_TO_CLIENTS;
    }

    /** Null = the crew page link stands until revoked. */
    public static function crewStandingLinkExpiryDaysFor(?int $agencyId): ?int
    {
        if (!$agencyId) {
            return self::DEFAULT_CREW_STANDING_LINK_EXPIRY_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('crew_standing_link_expiry_days');

        return $value !== null ? (int) $value : self::DEFAULT_CREW_STANDING_LINK_EXPIRY_DAYS;
    }

    /** 0 hides the "recently completed" list. */
    public static function crewPageRecentCompletedDaysFor(?int $agencyId): int
    {
        if (!$agencyId) {
            return self::DEFAULT_CREW_PAGE_RECENT_COMPLETED_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('crew_page_recent_completed_days');

        return $value !== null ? (int) $value : self::DEFAULT_CREW_PAGE_RECENT_COMPLETED_DAYS;
    }

    public static function crewPageUpcomingDaysFor(?int $agencyId): int
    {
        if (!$agencyId) {
            return self::DEFAULT_CREW_PAGE_UPCOMING_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('crew_page_upcoming_days');

        return $value !== null ? (int) $value : self::DEFAULT_CREW_PAGE_UPCOMING_DAYS;
    }
}
