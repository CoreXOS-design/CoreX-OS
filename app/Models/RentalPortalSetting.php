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
    /** rental-portal-access.md §16 — a signed lease gives its tenant(s) and landlord(s) portal access automatically. */
    public const DEFAULT_AUTO_PORTAL_ACCESS_ON_SIGNING = true;

    // .ai/specs/rental-work-orders.md §14.27.3 — crew links (Build 1's five).
    public const DEFAULT_CREW_LINKS_ENABLED = true;
    public const DEFAULT_CREW_JOB_LINK_EXPIRY_DAYS = 14;
    /** §17.4.7 — restated in COST terms: the crew also sees the cost figures the office entered. Never selling. */
    public const DEFAULT_CREW_LINK_SHOW_COSTS = false;
    public const DEFAULT_CREW_LINK_SHOW_TENANT_CONTACT = false;
    public const DEFAULT_NOTIFY_LANDLORD_ON_CREW_COMPLETION = true;

    // .ai/specs/rental-work-orders.md §14.27.3 — Build 2 (crew page + client visibility).
    public const CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED = 'in_progress_and_completed';
    public const CREW_PHOTOS_COMPLETED_ONLY = 'completed_only';
    public const CREW_PHOTO_VISIBILITY_OPTIONS = [self::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED, self::CREW_PHOTOS_COMPLETED_ONLY];
    public const DEFAULT_CREW_PHOTOS_VISIBLE_TO_CLIENTS = self::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED;
    /** null = the crew page link stands until it is revoked. */
    public const DEFAULT_CREW_STANDING_LINK_EXPIRY_DAYS = null;
    public const DEFAULT_CREW_PAGE_RECENT_COMPLETED_DAYS = 7;
    public const DEFAULT_CREW_PAGE_UPCOMING_DAYS = 14;

    // .ai/specs/rental-portal-access.md §22 — fault photos from the tenant / owner portal (client-side compression keeps phone photos well under the size).
    public const DEFAULT_FAULT_PHOTO_MAX_COUNT = 6;
    public const MAX_FAULT_PHOTO_COUNT = 10;
    public const DEFAULT_FAULT_PHOTO_MAX_MB = 8;
    public const MAX_FAULT_PHOTO_MB = 15;

    /**
     * §22 — the portal Home FAQ. The agency's wording, with the lease's own values merged in: {notice_days}, {earliest_termination_date},
     * {earliest_notice_date}, {lease_end_date}, {early_cancellation_terms}. A piece in [[double brackets]] is dropped whole when any
     * {value} inside it is not on the lease — so the portal never states a figure it does not have.
     */
    public const FAQ_KEYS = [
        'faq_tenant_notice_question', 'faq_tenant_notice_answer', 'faq_tenant_early_question', 'faq_tenant_early_answer',
        'faq_landlord_notice_question', 'faq_landlord_notice_answer', 'faq_landlord_early_question', 'faq_landlord_early_answer',
    ];
    public const FAQ_DEFAULTS = [
        'faq_tenant_notice_question' => 'Can I give notice?',
        'faq_tenant_notice_answer' => 'Yes, in writing[[, at least {notice_days} days before you want to move out]].[[ Your lease cannot end before {earliest_termination_date}.]][[ The earliest you can give notice is {earliest_notice_date}.]][[ Your lease runs until {lease_end_date}.]]',
        'faq_tenant_early_question' => 'What happens if I give notice before my lease expires?',
        'faq_tenant_early_answer' => '[[Notice that would end the lease before {earliest_termination_date} does not end it before then.]][[ {early_cancellation_terms}]] The full terms are in your signed lease under Documents.',
        'faq_landlord_notice_question' => 'Can the tenant give notice?',
        'faq_landlord_notice_answer' => 'Yes, in writing[[, at least {notice_days} days before they move out]].[[ The lease cannot end before {earliest_termination_date}.]][[ The earliest the tenant can give notice is {earliest_notice_date}.]][[ The lease runs until {lease_end_date}.]]',
        'faq_landlord_early_question' => 'What happens if the tenant gives notice before the lease expires?',
        'faq_landlord_early_answer' => '[[Notice that would end the lease before {earliest_termination_date} does not end it before then.]][[ {early_cancellation_terms}]] The full terms are in the signed lease under Documents.',
    ];

    protected $fillable = [
        'agency_id',
        'tenant_portal_enabled',
        'landlord_portal_enabled',
        'contractor_links_enabled',
        'contractor_secure_link_expiry_days',
        'notify_landlord_on_decision_needed',
        'notify_tenant_on_status_change',
        'auto_portal_access_on_signing',
        'crew_links_enabled',
        'crew_job_link_expiry_days',
        'crew_link_show_costs',
        'crew_link_show_tenant_contact',
        'notify_landlord_on_crew_completion',
        'crew_photos_visible_to_clients',
        'crew_standing_link_expiry_days',
        'crew_page_recent_completed_days',
        'crew_page_upcoming_days',
        'fault_photo_max_count',
        'fault_photo_max_mb',
        'faq_tenant_notice_question', 'faq_tenant_notice_answer', 'faq_tenant_early_question', 'faq_tenant_early_answer',
        'faq_landlord_notice_question', 'faq_landlord_notice_answer', 'faq_landlord_early_question', 'faq_landlord_early_answer',
    ];

    protected $casts = [
        'tenant_portal_enabled' => 'boolean',
        'landlord_portal_enabled' => 'boolean',
        'contractor_links_enabled' => 'boolean',
        'contractor_secure_link_expiry_days' => 'integer',
        'notify_landlord_on_decision_needed' => 'boolean',
        'notify_tenant_on_status_change' => 'boolean',
        'auto_portal_access_on_signing' => 'boolean',
        'crew_links_enabled' => 'boolean',
        'crew_job_link_expiry_days' => 'integer',
        'crew_link_show_costs' => 'boolean',
        'crew_link_show_tenant_contact' => 'boolean',
        'notify_landlord_on_crew_completion' => 'boolean',
        'crew_standing_link_expiry_days' => 'integer',
        'crew_page_recent_completed_days' => 'integer',
        'crew_page_upcoming_days' => 'integer',
        'fault_photo_max_count' => 'integer',
        'fault_photo_max_mb' => 'integer',
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

    public static function autoPortalAccessOnSigningFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'auto_portal_access_on_signing', self::DEFAULT_AUTO_PORTAL_ACCESS_ON_SIGNING);
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

    // ── .ai/specs/rental-work-orders.md §14.27.3 — crew links ──

    /** Master switch: off = every crew link (per-job and crew page) is "unavailable" at once. */
    public static function crewLinksEnabledFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'crew_links_enabled', self::DEFAULT_CREW_LINKS_ENABLED);
    }

    /** Per-job link validity in days (1-90). */
    public static function crewJobLinkExpiryDaysFor(?int $agencyId): int
    {
        if (!$agencyId) {
            return self::DEFAULT_CREW_JOB_LINK_EXPIRY_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('crew_job_link_expiry_days');

        return $value !== null ? (int) $value : self::DEFAULT_CREW_JOB_LINK_EXPIRY_DAYS;
    }

    /**
     * §17.4.7 — whether the crew's per-job view and the crew page also show the COST figures the office
     * entered. The crew can always type a cost on a line they add; this never shows selling, markup, margin or a job total.
     */
    public static function crewLinkShowCostsFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'crew_link_show_costs', self::DEFAULT_CREW_LINK_SHOW_COSTS);
    }

    /** The tenant's name + phone on the crew views. */
    public static function crewLinkShowTenantContactFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'crew_link_show_tenant_contact', self::DEFAULT_CREW_LINK_SHOW_TENANT_CONTACT);
    }

    /** Landlord email when the crew marks the work completed. */
    public static function notifyLandlordOnCrewCompletionFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'notify_landlord_on_crew_completion', self::DEFAULT_NOTIFY_LANDLORD_ON_CREW_COMPLETION);
    }

    // ── §22 — portal round 2 ────────────────────────────────────────────

    /** How many photos one fault report may carry (1-10). */
    public static function faultPhotoMaxCountFor(?int $agencyId): int
    {
        $value = $agencyId ? static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('fault_photo_max_count') : null;
        $n = $value !== null ? (int) $value : self::DEFAULT_FAULT_PHOTO_MAX_COUNT;

        return max(1, min(self::MAX_FAULT_PHOTO_COUNT, $n));
    }

    /** The size of ONE photo as it reaches the server, in MB (1-15). The page compresses before sending. */
    public static function faultPhotoMaxMbFor(?int $agencyId): int
    {
        $value = $agencyId ? static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('fault_photo_max_mb') : null;
        $n = $value !== null ? (int) $value : self::DEFAULT_FAULT_PHOTO_MAX_MB;

        return max(1, min(self::MAX_FAULT_PHOTO_MB, $n));
    }

    /** One FAQ wording (a question or an answer): the agency's own text when saved and not blank, else the default. */
    public static function faqTextFor(?int $agencyId, string $key): string
    {
        if (!array_key_exists($key, self::FAQ_DEFAULTS)) {
            return '';
        }
        $value = $agencyId ? static::withoutGlobalScopes()->where('agency_id', $agencyId)->value($key) : null;

        return is_string($value) && trim($value) !== '' ? $value : self::FAQ_DEFAULTS[$key];
    }
}
