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
    /** AT-508: informational phone notifications are ON unless the agency switches the default off (mandatory ones are never affected). */
    public const DEFAULT_PUSH_INFORMATIONAL = true;
    /** rental-portal-access.md §16 — a signed lease gives its tenant(s) and landlord(s) portal access automatically. */
    public const DEFAULT_AUTO_PORTAL_ACCESS_ON_SIGNING = true;

    // .ai/specs/rental-work-orders.md §14.27.3 — crew links (Build 1's five).
    public const DEFAULT_CREW_LINKS_ENABLED = true;
    public const DEFAULT_CREW_JOB_LINK_EXPIRY_DAYS = 14;
    /** §17.4.7 — restated in COST terms: the crew also sees the cost figures the office entered. Never selling. */
    public const DEFAULT_CREW_LINK_SHOW_COSTS = false;
    // AT-459 (Johan, 15 Oct 2026): the crew and the contractor get the tenant's name + phone unless the agency switches it off.
    public const DEFAULT_CREW_LINK_SHOW_TENANT_CONTACT = true;
    /** Audit C-C4: OFF by default - Johan's 14 Oct ruling (spec 22.17 item 6) is that the owner hears at the agent's close; an agency can choose to tell the owner when the work is reported finished (OwnerFinishNotice). */
    public const DEFAULT_NOTIFY_LANDLORD_ON_CREW_COMPLETION = false;

    // .ai/specs/rental-work-orders.md §14.27.3 — Build 2 (crew page + client visibility).
    public const CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED = 'in_progress_and_completed';
    public const CREW_PHOTOS_COMPLETED_ONLY = 'completed_only';
    public const CREW_PHOTO_VISIBILITY_OPTIONS = [self::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED, self::CREW_PHOTOS_COMPLETED_ONLY];
    public const DEFAULT_CREW_PHOTOS_VISIBLE_TO_CLIENTS = self::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED;
    /** A crew's standing home link lasts this many days unless the agency chooses otherwise (0 = stands until revoked). */
    public const DEFAULT_CREW_STANDING_LINK_EXPIRY_DAYS = 180;
    public const DEFAULT_CREW_PAGE_UPCOMING_DAYS = 14;

    // .ai/specs/rental-portal-access.md §22 — fault photos from the tenant / owner portal (client-side compression keeps phone photos well under the size).
    public const DEFAULT_FAULT_PHOTO_MAX_COUNT = 6;
    public const MAX_FAULT_PHOTO_COUNT = 10;
    public const DEFAULT_FAULT_PHOTO_MAX_MB = 8;
    public const MAX_FAULT_PHOTO_MB = 15;
    /** rentals-faults-work-orders.md §18 (AT-452) — the tenant also sees the agent's marks and per-photo notes on their own fault photos. Off unless the agency allows it. */
    public const DEFAULT_TENANT_SEES_AGENT_MARKS = false;
    /** AT-452 round 2 - the crew's job link goes to them automatically when a job card is made from a fault decision. On unless the agency turns it off. */
    public const DEFAULT_CREW_LINK_AUTO_SEND_ON_FAULT_JOB = true;
    /** AT-452 round 2 (F-OWNER) - days after a tenant-resolved report within which a new one of the same type is flagged "reported before". */
    public const DEFAULT_FAULT_RECURRENCE_WINDOW_DAYS = 90;
    public const MAX_FAULT_RECURRENCE_WINDOW_DAYS = 730;
    /** Audit fixes 19 Oct 2026 (C-A1): hours with no owner answer before the first reminder, then the second (after the first), then an agent queue row (after the second). */
    public const DEFAULT_OWNER_REMINDER_HOURS = 24;
    public const DEFAULT_OWNER_SECOND_REMINDER_HOURS = 24;
    public const DEFAULT_OWNER_QUEUE_AFTER_HOURS = 24;
    public const MAX_OWNER_CHASE_HOURS = 720;
    /** C-D3: a contractor / crew link's expiry slides forward every time it is used. On unless the agency turns it off. */
    public const DEFAULT_LINK_RENEW_ON_USE = true;
    /** C-C6: the largest quote a contractor may enter on their link (Rand). */
    public const DEFAULT_QUOTE_MAX_AMOUNT = 1000000;
    public const MAX_QUOTE_MAX_AMOUNT = 99999999;

    // Portal security timings and limits (audit 2026-10-19). Each is an agency setting; a blank column resolves to the default.
    /** D12 - how long a browser remembers a portal sign-in. rental-portal-access.md section 28.5 / audit A-L1: absolute 7 days. */
    public const DEFAULT_PORTAL_REMEMBER_DAYS = 7;
    public const MAX_PORTAL_REMEMBER_DAYS = 30;
    /** D4 - the phone app stays signed in this many days after its LAST use (renewed on use). client-auth.md: 30 days, sliding. */
    public const DEFAULT_PORTAL_APP_TOKEN_DAYS = 30;
    public const MAX_PORTAL_APP_TOKEN_DAYS = 90;
    /** D10 - requests per minute one signed-in portal person may make (the picture uploads count too). */
    public const DEFAULT_PORTAL_API_PER_MINUTE = 120;
    public const MIN_PORTAL_API_PER_MINUTE = 30;
    public const MAX_PORTAL_API_PER_MINUTE = 1000;
    /** D14 - how long a picture link handed to the portal keeps working. The portal asks again every time a page loads. */
    public const DEFAULT_PORTAL_PHOTO_LINK_MINUTES = 120;
    public const MIN_PORTAL_PHOTO_LINK_MINUTES = 5;
    public const MAX_PORTAL_PHOTO_LINK_MINUTES = 1440;
    /** D8 - how long the signed reference inside a portal email link is recognised (it only pre-fills the sign-in and shows the agency's header). */
    public const DEFAULT_PORTAL_LINK_DAYS = 60;
    public const MAX_PORTAL_LINK_DAYS = 365;

    /**
     * §22 — the portal Home FAQ. The agency's wording, with the lease's own values merged in: {notice_days}, {earliest_termination_date},
     * {lease_end_date}, {early_cancellation_terms}. A piece in [[double brackets]] is dropped whole when any
     * {value} inside it is not on the lease — so the portal never states a figure it does not have.
     */
    public const FAQ_KEYS = [
        'faq_tenant_notice_question', 'faq_tenant_notice_answer', 'faq_tenant_early_question', 'faq_tenant_early_answer',
        'faq_landlord_notice_question', 'faq_landlord_notice_answer', 'faq_landlord_early_question', 'faq_landlord_early_answer',
        'faq_tenant_cancel_yes', 'faq_tenant_cancel_no', 'faq_landlord_cancel_yes', 'faq_landlord_cancel_no',
    ];
    public const FAQ_DEFAULTS = [
        'faq_tenant_notice_question' => 'Can I give notice?',
        'faq_tenant_notice_answer' => 'Yes, in writing[[, at least {notice_period} before you want to move out]].[[ Your lease cannot end before {earliest_termination_date}.]][[ You can give notice {notice_from}.]][[ Your lease runs until {lease_end_date}.]][[ To leave when it ends, give notice by {notice_by_date}.]]',
        'faq_tenant_early_question' => 'What happens if I give notice before my lease expires?',
        'faq_tenant_early_answer' => '[[Notice that would end the lease before {earliest_termination_date} does not end it before then.]][[ {early_cancellation_terms}]] The full terms are in your signed lease under Documents.',
        'faq_landlord_notice_question' => 'Can the tenant give notice?',
        'faq_landlord_notice_answer' => 'Yes, in writing[[, at least {notice_period} before they move out]].[[ The lease cannot end before {earliest_termination_date}.]][[ The tenant can give notice {notice_from}.]][[ The lease runs until {lease_end_date}.]][[ To leave when it ends, the tenant must give notice by {notice_by_date}.]]',
        'faq_landlord_early_question' => 'What happens if the tenant gives notice before the lease expires?',
        'faq_landlord_early_answer' => '[[Notice that would end the lease before {earliest_termination_date} does not end it before then.]][[ {early_cancellation_terms}]] The full terms are in the signed lease under Documents.',
        // The sentence {early_cancellation_terms} stands for: the lease says early cancellation IS / IS NOT allowed.
        'faq_tenant_cancel_yes' => 'You may cancel early[[ by giving {early_cancellation_notice} written notice]].[[ A penalty applies: {early_cancellation_penalty}]]',
        'faq_tenant_cancel_no' => 'Your lease does not allow early cancellation.',
        'faq_landlord_cancel_yes' => 'The tenant may cancel early[[ by giving {early_cancellation_notice} written notice]].[[ A penalty applies: {early_cancellation_penalty}]]',
        'faq_landlord_cancel_no' => 'This lease does not allow the tenant to cancel early.',
    ];

    protected $fillable = [
        'agency_id',
        'tenant_portal_enabled',
        'landlord_portal_enabled',
        'contractor_links_enabled',
        'contractor_secure_link_expiry_days',
        'notify_landlord_on_decision_needed',
        'notify_tenant_on_status_change',
        'push_informational_default',
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
        'tenant_sees_agent_marks',
        'crew_link_auto_send_on_fault_job',
        'fault_recurrence_window_days',
        'portal_remember_days', 'portal_app_token_days', 'portal_api_per_minute', 'portal_photo_link_minutes', 'portal_link_days',
        'owner_reminder_hours', 'owner_second_reminder_hours', 'owner_queue_after_hours', 'link_renew_on_use', 'quote_max_amount',
        'faq_tenant_notice_question', 'faq_tenant_notice_answer', 'faq_tenant_early_question', 'faq_tenant_early_answer',
        'faq_landlord_notice_question', 'faq_landlord_notice_answer', 'faq_landlord_early_question', 'faq_landlord_early_answer',
        'faq_tenant_cancel_yes', 'faq_tenant_cancel_no', 'faq_landlord_cancel_yes', 'faq_landlord_cancel_no',
    ];

    protected $casts = [
        'tenant_portal_enabled' => 'boolean',
        'landlord_portal_enabled' => 'boolean',
        'contractor_links_enabled' => 'boolean',
        'contractor_secure_link_expiry_days' => 'integer',
        'notify_landlord_on_decision_needed' => 'boolean',
        'notify_tenant_on_status_change' => 'boolean',
        'push_informational_default' => 'boolean',
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
        'tenant_sees_agent_marks' => 'boolean',
        'crew_link_auto_send_on_fault_job' => 'boolean',
        'fault_recurrence_window_days' => 'integer',
        'portal_remember_days' => 'integer',
        'portal_app_token_days' => 'integer',
        'portal_api_per_minute' => 'integer',
        'portal_photo_link_minutes' => 'integer',
        'portal_link_days' => 'integer',
        'owner_reminder_hours' => 'integer',
        'owner_second_reminder_hours' => 'integer',
        'owner_queue_after_hours' => 'integer',
        'link_renew_on_use' => 'boolean',
        'quote_max_amount' => 'integer',
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

    public static function pushInformationalDefaultFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'push_informational_default', self::DEFAULT_PUSH_INFORMATIONAL);
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

    /**
     * Days a crew's standing home link lasts. Nothing stored = the default (180). A stored 0 is the agency's explicit
     * "keep it until I revoke it" — returned as null, which the token service reads as no expiry.
     */
    public static function crewStandingLinkExpiryDaysFor(?int $agencyId): ?int
    {
        $days = self::crewStandingLinkExpiryDaysSettingFor($agencyId);

        return $days > 0 ? $days : null;
    }

    /** The setting as the agency typed it (0 = until revoked, else 1-365) — what the settings page and the wizard show, so saving them unchanged never flips a choice. */
    public static function crewStandingLinkExpiryDaysSettingFor(?int $agencyId): int
    {
        $value = $agencyId ? static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('crew_standing_link_expiry_days') : null;

        return $value !== null ? max(0, (int) $value) : self::DEFAULT_CREW_STANDING_LINK_EXPIRY_DAYS;
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

    /** AT-452 — may the tenant see the agent's marks and notes on their own fault photos? */
    public static function tenantSeesAgentMarksFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'tenant_sees_agent_marks', self::DEFAULT_TENANT_SEES_AGENT_MARKS);
    }

    /** AT-452 round 2 - send the crew their job link automatically when a job card is created from a fault decision. */
    public static function crewLinkAutoSendOnFaultJobFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'crew_link_auto_send_on_fault_job', self::DEFAULT_CREW_LINK_AUTO_SEND_ON_FAULT_JOB);
    }

    /** AT-452 round 2 (F-OWNER) - the recurrence window in days (1-730; unset = 90). */
    public static function faultRecurrenceWindowDaysFor(?int $agencyId): int
    {
        $value = $agencyId ? static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('fault_recurrence_window_days') : null;
        $n = $value !== null ? (int) $value : self::DEFAULT_FAULT_RECURRENCE_WINDOW_DAYS;

        return max(1, min(self::MAX_FAULT_RECURRENCE_WINDOW_DAYS, $n));
    }

    /** The saved whole number for one bounded setting column, else its default, clamped to [min, max]. */
    private static function boundedIntFor(?int $agencyId, string $column, int $default, int $min, int $max): int
    {
        $value = $agencyId ? static::withoutGlobalScopes()->where('agency_id', $agencyId)->value($column) : null;
        $n = $value !== null ? (int) $value : $default;

        return max($min, min($max, $n));
    }

    /** D12 - days a browser remembers a portal sign-in (1-30, default 7). */
    public static function portalRememberDaysFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'portal_remember_days', self::DEFAULT_PORTAL_REMEMBER_DAYS, 1, self::MAX_PORTAL_REMEMBER_DAYS);
    }

    /** D4 - days the phone app stays signed in after its last use (1-90, default 30). */
    public static function portalAppTokenDaysFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'portal_app_token_days', self::DEFAULT_PORTAL_APP_TOKEN_DAYS, 1, self::MAX_PORTAL_APP_TOKEN_DAYS);
    }

    /** D10 - portal requests per minute per signed-in person (30-1000, default 120). */
    public static function portalApiPerMinuteFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'portal_api_per_minute', self::DEFAULT_PORTAL_API_PER_MINUTE, self::MIN_PORTAL_API_PER_MINUTE, self::MAX_PORTAL_API_PER_MINUTE);
    }

    /** D14 - minutes a picture link shown in the portal keeps working (5-1440, default 120). */
    public static function portalPhotoLinkMinutesFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'portal_photo_link_minutes', self::DEFAULT_PORTAL_PHOTO_LINK_MINUTES, self::MIN_PORTAL_PHOTO_LINK_MINUTES, self::MAX_PORTAL_PHOTO_LINK_MINUTES);
    }

    /** D8 - days the signed reference in a portal email link is recognised (1-365, default 60). */
    public static function portalLinkDaysFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'portal_link_days', self::DEFAULT_PORTAL_LINK_DAYS, 1, self::MAX_PORTAL_LINK_DAYS);
    }

    /** C-A1 - hours with no answer from the owner before the FIRST reminder (1-720; unset = 24). */
    public static function ownerReminderHoursFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'owner_reminder_hours', self::DEFAULT_OWNER_REMINDER_HOURS, 1, self::MAX_OWNER_CHASE_HOURS);
    }

    /** C-A1 - hours after the first reminder before the SECOND (1-720; unset = 24). */
    public static function ownerSecondReminderHoursFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'owner_second_reminder_hours', self::DEFAULT_OWNER_SECOND_REMINDER_HOURS, 1, self::MAX_OWNER_CHASE_HOURS);
    }

    /** C-A1 - hours after the second reminder before the fault goes on the agent's queue as "owner has not answered" (1-720; unset = 24). */
    public static function ownerQueueAfterHoursFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'owner_queue_after_hours', self::DEFAULT_OWNER_QUEUE_AFTER_HOURS, 1, self::MAX_OWNER_CHASE_HOURS);
    }

    /** C-D3 - a contractor / crew link renews its expiry each time it is used. */
    public static function linkRenewOnUseFor(?int $agencyId): bool
    {
        return self::boolFor($agencyId, 'link_renew_on_use', self::DEFAULT_LINK_RENEW_ON_USE);
    }

    /** C-C6 - the largest quote (Rand) a contractor may enter on their link. */
    public static function quoteMaxAmountFor(?int $agencyId): int
    {
        return self::boundedIntFor($agencyId, 'quote_max_amount', self::DEFAULT_QUOTE_MAX_AMOUNT, 1, self::MAX_QUOTE_MAX_AMOUNT);
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
