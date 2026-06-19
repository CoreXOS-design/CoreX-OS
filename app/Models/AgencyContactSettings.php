<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyContactSettings extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_contact_settings';

    protected $fillable = [
        'agency_id',
        'sharing_mode', // DEPRECATED — visibility now governed by role_permissions.scope
        'buyer_pipeline_default_scope',
        'duplicate_mode',
        'duplicate_match_fields',
        'buyer_warm_days',
        'buyer_cold_days',
        'buyer_lost_days',
        'contact_retention_years',
        'consent_retention_years',
        'access_log_retention_years',
        // AT-66 §3 — feedback fan-out role map (agency-configurable).
        'feedback_seller_roles',
        'feedback_buyer_source',
        'feedback_lessor_roles',
        'feedback_lessee_source',
    ];

    protected $casts = [
        'duplicate_match_fields' => 'array',
        'buyer_warm_days' => 'integer',
        'buyer_cold_days' => 'integer',
        'buyer_lost_days' => 'integer',
        'contact_retention_years' => 'integer',
        'consent_retention_years' => 'integer',
        'access_log_retention_years' => 'integer',
        // AT-66 §3
        'feedback_seller_roles' => 'array',
        'feedback_buyer_source' => 'array',
        'feedback_lessor_roles' => 'array',
        'feedback_lessee_source' => 'array',
    ];

    /**
     * AT-66 §3 — defaults for the feedback fan-out role map. Never
     * hardcoded at the call site; resolved here so they can be overridden
     * per agency and so a NULL column (row predating the migration) still
     * yields a sensible map.
     *
     *  - seller_roles  : contact_property.role values treated as sellers.
     *  - buyer_source  : calendar_event_links contact-link roles treated
     *                    as the buyer (notation "attendee:<role>").
     *  - lessor_roles / lessee_source : future rentals, defined now, no UI.
     */
    public const FANOUT_DEFAULTS = [
        'seller_roles'  => ['seller', 'owner'],
        'buyer_source'  => ['attendee:buyer_contact', 'attendee:attendee'],
        'lessor_roles'  => ['landlord', 'lessor'],
        'lessee_source' => ['attendee:lessee'],
    ];

    /**
     * Get settings for an agency, creating defaults if none exist.
     */
    public static function forAgency(int $agencyId): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId],
            [
                'sharing_mode' => 'branch',
                'buyer_pipeline_default_scope' => 'own',
                'duplicate_mode' => 'soft_warn',
                'duplicate_match_fields' => ['phone', 'email', 'id_number'],
                'buyer_warm_days' => 14,
                'buyer_cold_days' => 30,
                'buyer_lost_days' => 60,
                'contact_retention_years' => 5,
                'consent_retention_years' => 5,
                'access_log_retention_years' => 5,
                'feedback_seller_roles' => self::FANOUT_DEFAULTS['seller_roles'],
                'feedback_buyer_source' => self::FANOUT_DEFAULTS['buyer_source'],
                'feedback_lessor_roles' => self::FANOUT_DEFAULTS['lessor_roles'],
                'feedback_lessee_source' => self::FANOUT_DEFAULTS['lessee_source'],
            ]
        );
    }

    /**
     * AT-66 §3 — the resolved fan-out role map for this agency: stored
     * overrides where present, code defaults where NULL. Always returns a
     * complete, non-empty map so callers never special-case missing config.
     */
    public function fanoutConfig(): array
    {
        return [
            'seller_roles'  => $this->feedback_seller_roles  ?: self::FANOUT_DEFAULTS['seller_roles'],
            'buyer_source'  => $this->feedback_buyer_source  ?: self::FANOUT_DEFAULTS['buyer_source'],
            'lessor_roles'  => $this->feedback_lessor_roles  ?: self::FANOUT_DEFAULTS['lessor_roles'],
            'lessee_source' => $this->feedback_lessee_source ?: self::FANOUT_DEFAULTS['lessee_source'],
        ];
    }

    /**
     * AT-66 §3 — the calendar_event_links contact-link roles that count as
     * the buyer fan-out target, derived from buyer_source by stripping the
     * "attendee:" prefix. Resolved here so the controller never parses the
     * notation inline.
     */
    public function buyerLinkRoles(): array
    {
        return collect($this->fanoutConfig()['buyer_source'])
            ->map(fn ($s) => str_starts_with((string) $s, 'attendee:') ? substr($s, strlen('attendee:')) : $s)
            ->filter()
            ->values()
            ->all();
    }
}
