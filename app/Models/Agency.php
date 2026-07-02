<?php

namespace App\Models;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\InformationOfficerAppointment;
use App\Models\Compliance\RmcpVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agency extends Model
{
    use SoftDeletes;

    /**
     * Provision the new agency's role set from the global templates as soon
     * as it is created — UI, seeders, and factories all go through here so no
     * agency is ever left without its own roles/permissions
     * (.ai/specs/roles-permissions.md §6). Idempotent + non-destructive.
     */
    protected static function booted(): void
    {
        static::created(function (Agency $agency) {
            \App\Services\RoleProvisioningService::provisionForAgency($agency);
        });
    }

    /**
     * WhatsApp launch-mode constants per the 2026-05-14 hotfix. Controls how
     * the "Open WhatsApp" buttons hand off to the user's WhatsApp app:
     *
     *   WHATSAPP_LAUNCH_APP — `whatsapp://send?...` deeplink (no intermediate page;
     *                         requires the app to be installed).
     *   WHATSAPP_LAUNCH_WEB — `https://wa.me/...` universal-fallback URL (default;
     *                         shows the app/web/download chooser page).
     */
    public const WHATSAPP_LAUNCH_APP = 'whatsapp_app';
    public const WHATSAPP_LAUNCH_WEB = 'whatsapp_web';
    public const WHATSAPP_LAUNCH_MODES = [
        self::WHATSAPP_LAUNCH_APP => 'Open app directly (no intermediate page)',
        self::WHATSAPP_LAUNCH_WEB => 'Open WhatsApp web (with fallback for users without the app)',
    ];

    /**
     * AT-117 §4a — outreach send-window defaults.
     *
     * Legally-correct SA CPA direct-marketing permitted times, but AGENCY-EDITABLE
     * (never hardcoded law): an agency overrides these via the company-settings UI.
     * A NULL `outreach_send_window` column resolves to these defaults so existing
     * agencies inherit the correct window without a backfill.
     *
     * Shape: each weekday key => ['enabled' => bool, 'start' => 'HH:MM'|null,
     * 'end' => 'HH:MM'|null], plus 'public_holidays_off' => bool.
     */
    public const OUTREACH_SEND_WINDOW_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const OUTREACH_SEND_WINDOW_DEFAULT = [
        'mon' => ['enabled' => true,  'start' => '08:00', 'end' => '20:00'],
        'tue' => ['enabled' => true,  'start' => '08:00', 'end' => '20:00'],
        'wed' => ['enabled' => true,  'start' => '08:00', 'end' => '20:00'],
        'thu' => ['enabled' => true,  'start' => '08:00', 'end' => '20:00'],
        'fri' => ['enabled' => true,  'start' => '08:00', 'end' => '20:00'],
        'sat' => ['enabled' => true,  'start' => '09:00', 'end' => '13:00'],
        'sun' => ['enabled' => false, 'start' => null,    'end' => null],
        'public_holidays_off' => true,
    ];

    /**
     * Property24 syndication defaults (AT-101). The single source of truth for
     * the "current behaviour" fallback used everywhere the per-agency setting is
     * unset: photo cap = 150, HTTP read timeout = 120s (job timeout derives as
     * read + 60 = 180s). Migration column defaults are kept in lockstep.
     *
     * Photo default raised 30 → 150 after the AT-101 probe proved 150 photos
     * round-trip in 21.7s on base64 — 5.5× under the 120s read timeout. See
     * .ai/audits/2026-06-26-at101-photo-cap-probe.md.
     */
    public const P24_DEFAULT_MAX_PHOTOS = 150;
    public const P24_DEFAULT_HTTP_READ_TIMEOUT = 120;

    /** Configured P24 photo cap for this agency, falling back to the default. */
    public function p24MaxPhotos(): int
    {
        return (int) ($this->p24_max_photos ?? self::P24_DEFAULT_MAX_PHOTOS);
    }

    /** Configured P24 HTTP read timeout (seconds), falling back to the default. */
    public function p24HttpReadTimeout(): int
    {
        return (int) ($this->p24_http_read_timeout ?? self::P24_DEFAULT_HTTP_READ_TIMEOUT);
    }

    /**
     * Private Property (PP) syndication defaults. Matches the P24 photo cap
     * (150) — PP receives image URLs and downloads them inside its SOAP
     * transaction, so an over-large gallery can time PP out; the cap stays
     * per-agency tunable via agencies.pp_max_photos. Migration column default
     * is kept in lockstep with this constant.
     */
    public const PP_DEFAULT_MAX_PHOTOS = 150;

    /** Configured PP photo cap for this agency, falling back to the default. */
    public function ppMaxPhotos(): int
    {
        return (int) ($this->pp_max_photos ?? self::PP_DEFAULT_MAX_PHOTOS);
    }

    /**
     * AI monthly budget status constants. Returned by aiBudgetStatus().
     * Drives the per-agency AI budget UI banner and the AnthropicGateway
     * pre-call gate (capped = no further calls).
     */
    public const AI_BUDGET_STATUS_HEALTHY  = 'healthy';
    public const AI_BUDGET_STATUS_WARNING  = 'warning';
    public const AI_BUDGET_STATUS_CRITICAL = 'critical';
    public const AI_BUDGET_STATUS_CAPPED   = 'capped';

    protected $fillable = [
        'name',
        'slug',
        'wa_history_backfill', // AT-135 — read-only WA body backfill toggle
        'wa_self_link_enabled', // AT-156 — agents may self-link WhatsApp capture (default on)
        'wa_session_prefix', // AT-156 — WAHA session-name prefix (null => agency{id})

        'viewing_pack_redaction_dpi', // AT-107 Step 5b — redaction render DPI (null = default 150)
        'viewing_pack_default_duration_minutes', // AT-107 Step 8 — default viewing duration (null = 60)
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
        'public_contact',
        'fic_no',
        'sidebar_color',
        'icon_color',
        'default_color',
        'button_color',
        'ai_voice_enabled',
        'ai_image_recognition_enabled',
        'logo_path',
        'email_disclaimer',
        'marketing_unsubscribe_footer',
        'outreach_live_deal_statuses',
        'popi_url',
        'privacy_policy_markdown',
        'privacy_policy_token',
        'privacy_policy_published_at',
        'whatsapp_launch_mode_agent',
        'whatsapp_launch_mode_seller',
        'outreach_send_window', // AT-117 §4a — JSON send-window config
        'communication_first_poll_days', // AT-122 — first-poll IMAP backfill window override (days)
        'outreach_queue_expiry_hours', // AT-117 §8 — surfaced-row lifetime (null = end of day)
        'outreach_queue_daily_cap_per_agent', // AT-117 §8 — per-agent daily queue cap (null = none)
        'restrict_consent_outreach_to_full_status', // AT-142 — restrict consent outreach to full-status practitioners (default off)
        'prospecting_pitch_temp_lock_minutes',
        'is_active',
        'is_demo',
        'require_external_access_authorization',
        'dashboard_settings_mode',
        'split_branches_enabled',
        'show_prospected_badge',
        'properties_sort_mode',
        'properties_status_priority',
        'p24_agency_id',
        'p24_agency_label',
        'p24_username',
        'p24_password',
        'p24_user_group_id',
        'p24_enabled',
        'p24_locations_synced_at',
        'p24_last_sync_error',
        'p24_max_photos',
        'p24_http_read_timeout',
        'pp_enabled',
        'pp_username',
        'pp_password',
        'pp_branch_guid',
        'pp_wsdl',
        'pp_sandbox',
        'pp_image_base_url',
        'pp_webhook_secret',
        'pp_max_photos',
        'pp_last_sync_error',
        'pp_locations_synced_at',
        'pp_locations_last_error',
        'default_branch_id',
        'whistleblow_approver_user_ids',
        'whistleblow_compliance_officer_email',
        'whistleblow_tier_recipients',
        // Communication Archive ingestion filter (AT-43).
        'communication_ingest_drop_noreply',
        'communication_ingest_blocklist_domains',
        // Provisional outbound reconciliation knobs (AT-59).
        'communication_reconcile_window_minutes',
        'communication_provisional_prune_hours',
        // MIC Phase B2 — per-agency AI monthly budget cap.
        'ai_monthly_budget_zar',
        'ai_budget_warning_pct',
        'ai_budget_hard_cap_pct',
        'ai_budget_overage_allowed',
        'ai_budget_last_warned_at',
        'ai_budget_last_hard_stopped_at',
        // Presentations V2 Phase 2 — CMA coverage thresholds.
        'presentations_coverage_rich_threshold',
        'presentations_coverage_moderate_threshold',
        'presentations_coverage_thin_threshold',
        'presentations_default_period_months',
        // Presentations V2 Phase 3b — comp scope + radius defaults.
        'presentations_default_comp_scope',
        'presentations_default_radius_m',
        // SS presentations — dedicated complex/sectional sales section toggle.
        'ss_show_complex_section',
        // Presentations V2 Phase 3e — holding-cost defaults.
        'presentations_default_rates_per_million_zar',
        'presentations_default_levies_sectional_per_m2_zar',
        'presentations_default_insurance_per_million_zar',
        'presentations_default_utilities_zar',
        'presentations_default_opportunity_cost_pct',
        // Presentations V2 Phase 4 — snapshot share link defaults.
        'snapshot_link_default_expiry_days',
        'snapshot_link_ip_masking',
        // Presentations V2 Phase 5 — teaser section visibility toggles.
        'teaser_default_show_suburb_stats',
        'teaser_default_show_market_position',
        'teaser_default_show_asking_range',
        'teaser_default_show_holding_cost_summary',
        // Build 4 — full report section toggle defaults.
        'presentations_default_show_executive_summary',
        'presentations_default_show_market_overview',
        'presentations_default_show_recent_sales',
        'presentations_default_show_spatial_view',
        'presentations_default_show_cma_analysis',
        'presentations_default_show_active_competition',
        'presentations_default_show_inflow_absorption',
        'presentations_default_show_holding_cost',
        'presentations_default_show_pricing_strategy',
        // Build 8b — CmaComputeService cleaning controls (recency + IQR).
        'cma_compute_recency_months',
        'cma_compute_iqr_multiplier',
        // AT-22 §0.1/§1/§1.5/§5 — comp-selection gate-then-rank + range thresholds.
        // Read by App\Services\Presentations\CompPoolBuilder::configForAgency().
        'comp_price_band_pct',
        'comp_erf_band_pct',
        'comp_radius_m',
        'comp_radius_widen_steps',
        'comp_radius_max_m',
        'comp_min_count',
        'comp_max_count',
        'anchor_divergence_pct',
        'range_lower_pct',
        'range_upper_pct',
        // PRES-CMA-REALFIX — recommended-band half-widths (± % around the
        // evaluated value / middle). Distinct from range_lower/upper_pct,
        // which are pool-distribution PERCENTILES (25/75), not ± fractions.
        'cma_band_lower_pct',
        'cma_band_upper_pct',
        // Competitor Stock — agency-configurable scorer thresholds.
        'competitor_stock_default_beds_tolerance',
        'competitor_stock_default_price_tolerance_pct',
        'competitor_stock_min_score',
        'competitor_stock_min_same_type',
        'competitor_stock_default_display_count',
        'competitor_stock_weights',
        'presentations_map_provider',
        // Holding Cost — freehold component Tier-2 defaults.
        'presentations_default_garden_zar',
        'presentations_default_pool_zar',
        'presentations_default_security_zar',
        // Agency Public API — master "website is live" switch + public website settings.
        'website_enabled',
        'website_url',
        'website_tagline',
        'website_about',
        'website_social_facebook',
        'website_social_instagram',
        'website_social_linkedin',
        'website_social_youtube',
        'website_contact_email',
        'website_contact_phone',
        'website_address',
        'website_open_hours',
        'website_show_agents',
        'website_show_listings',
        'website_show_branches',
        'website_agent_order_mode',
        'website_branch_order_mode',
        // Per-agency maintenance mode (AT-93) — tenant-level, enforced after
        // login by AgencyMaintenanceGate. System Owners bypass.
        'maintenance_mode',
        'maintenance_message',
        'maintenance_started_at',
    ];

    /** Website agent ordering modes. */
    public const AGENT_ORDER_ALPHABETICAL = 'alphabetical';
    public const AGENT_ORDER_CUSTOM       = 'custom';

    /** Website branch ordering modes (mirror the agent modes). */
    public const BRANCH_ORDER_ALPHABETICAL = 'alphabetical';
    public const BRANCH_ORDER_CUSTOM       = 'custom';

    protected $casts = [
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'wa_history_backfill' => 'boolean', // AT-135 — read-only WA body backfill toggle (default on)
        'wa_self_link_enabled' => 'boolean', // AT-156 — WhatsApp self-link toggle (default on)
        'outreach_send_window' => 'array', // AT-117 §4a — send-window config (null => defaults)
        'restrict_consent_outreach_to_full_status' => 'boolean', // AT-142 — consent-outreach full-status restriction (default off)
        'outreach_queue_expiry_hours' => 'integer', // AT-117 §8
        'outreach_queue_daily_cap_per_agent' => 'integer', // AT-117 §8
        'viewing_pack_redaction_dpi' => 'integer', // AT-107 Step 5b
        'viewing_pack_default_duration_minutes' => 'integer', // AT-107 Step 8

        // Per-agency maintenance mode (AT-93).
        'maintenance_mode' => 'boolean',
        'maintenance_started_at' => 'datetime',
        'ss_show_complex_section' => 'boolean',
        'ai_voice_enabled' => 'boolean',
        'ai_image_recognition_enabled' => 'boolean',
        'privacy_policy_published_at' => 'datetime',
        'require_external_access_authorization' => 'boolean',
        'split_branches_enabled' => 'boolean',
        'show_prospected_badge' => 'boolean',
        'default_branch_id' => 'integer',
        'p24_password' => 'encrypted',
        'p24_enabled' => 'boolean',
        'p24_max_photos' => 'integer',
        'p24_http_read_timeout' => 'integer',
        'p24_locations_synced_at' => 'datetime',
        'pp_enabled' => 'boolean',
        'pp_sandbox' => 'boolean',
        'pp_password' => 'encrypted',
        'pp_webhook_secret' => 'encrypted',
        'pp_locations_synced_at' => 'datetime',
        'whistleblow_approver_user_ids' => 'array',
        'whistleblow_tier_recipients' => 'array',
        // AT-50 — per-agency override of which deals_v2 statuses count as live.
        'outreach_live_deal_statuses' => 'array',
        // Properties list default ordering (agency-wide).
        'properties_status_priority' => 'array',
        // Communication Archive ingestion filter (AT-43) — null = inherit config default.
        'communication_ingest_drop_noreply'      => 'boolean',
        'communication_ingest_blocklist_domains' => 'array',
        'communication_reconcile_window_minutes' => 'integer',
        'communication_provisional_prune_hours'  => 'integer',
        // MIC Phase B2 — AI budget casts.
        'ai_monthly_budget_zar'          => 'decimal:2',
        'ai_budget_warning_pct'          => 'integer',
        'ai_budget_hard_cap_pct'         => 'integer',
        'ai_budget_overage_allowed'      => 'boolean',
        'ai_budget_last_warned_at'       => 'datetime',
        'ai_budget_last_hard_stopped_at' => 'datetime',
        // Presentations V2 Phase 2.
        'presentations_coverage_rich_threshold'     => 'integer',
        'presentations_coverage_moderate_threshold' => 'integer',
        'presentations_coverage_thin_threshold'     => 'integer',
        'presentations_default_period_months'       => 'integer',
        // Presentations V2 Phase 3b.
        'presentations_default_radius_m'            => 'integer',
        // Presentations V2 Phase 3e — holding-cost casts.
        'presentations_default_rates_per_million_zar'        => 'integer',
        'presentations_default_levies_sectional_per_m2_zar'  => 'integer',
        'presentations_default_insurance_per_million_zar'    => 'integer',
        'presentations_default_utilities_zar'                => 'integer',
        'presentations_default_opportunity_cost_pct'         => 'decimal:2',
        // Presentations V2 Phase 4 — snapshot link defaults.
        'snapshot_link_default_expiry_days'                  => 'integer',
        'snapshot_link_ip_masking'                           => 'boolean',
        // Presentations V2 Phase 5 — teaser section toggles.
        'teaser_default_show_suburb_stats'                   => 'boolean',
        'teaser_default_show_market_position'                => 'boolean',
        'teaser_default_show_asking_range'                   => 'boolean',
        'teaser_default_show_holding_cost_summary'           => 'boolean',
        // Build 4 — full report section toggle defaults.
        'presentations_default_show_executive_summary'  => 'boolean',
        'presentations_default_show_market_overview'    => 'boolean',
        'presentations_default_show_recent_sales'       => 'boolean',
        'presentations_default_show_spatial_view'       => 'boolean',
        'presentations_default_show_cma_analysis'       => 'boolean',
        'presentations_default_show_active_competition' => 'boolean',
        'presentations_default_show_inflow_absorption'  => 'boolean',
        'presentations_default_show_holding_cost'       => 'boolean',
        'presentations_default_show_pricing_strategy'   => 'boolean',
        // Build 8b — CmaComputeService cleaning controls.
        'cma_compute_recency_months' => 'integer',
        'cma_compute_iqr_multiplier' => 'decimal:2',
        // AT-22 — comp-selection + range thresholds. widen_steps stays a
        // string (CSV ladder, parsed by CompPoolBuilder::parseSteps).
        'comp_price_band_pct'   => 'decimal:2',
        'comp_erf_band_pct'     => 'decimal:2',
        'comp_radius_m'         => 'integer',
        'comp_radius_max_m'     => 'integer',
        'comp_min_count'        => 'integer',
        'comp_max_count'        => 'integer',
        'anchor_divergence_pct' => 'decimal:2',
        'range_lower_pct'       => 'integer',
        'range_upper_pct'       => 'integer',
        'cma_band_lower_pct'    => 'decimal:2',
        'cma_band_upper_pct'    => 'decimal:2',
        // Competitor Stock — agency-configurable scorer thresholds.
        'competitor_stock_default_beds_tolerance'      => 'integer',
        'competitor_stock_default_price_tolerance_pct' => 'integer',
        'competitor_stock_min_score'                   => 'integer',
        'competitor_stock_min_same_type'               => 'integer',
        'competitor_stock_default_display_count'       => 'integer',
        'competitor_stock_weights'                     => 'array',
        // Holding Cost — freehold component Tier-2 defaults.
        'presentations_default_garden_zar'   => 'integer',
        'presentations_default_pool_zar'     => 'integer',
        'presentations_default_security_zar' => 'integer',
        // Agency Public API — website flags.
        'website_enabled'       => 'boolean',
        'website_show_agents'   => 'boolean',
        'website_show_listings' => 'boolean',
        'website_show_branches' => 'boolean',
        'website_open_hours'    => 'array',
    ];

    /**
     * Build 4 — return the per-section default toggle map keyed by section
     * key (matching PresentationVersion::SECTIONS_CATALOGUE). Used by the
     * compiler to seed enabled_sections_json on freshly compiled versions.
     *
     * @return array<string, bool>
     */
    public function sectionDefaults(): array
    {
        $map = [];
        foreach (\App\Models\PresentationVersion::SECTIONS_CATALOGUE as $key => $_label) {
            $col = 'presentations_default_show_' . $key;
            $map[$key] = (bool) ($this->{$col} ?? true);
        }
        // Floor is force-on regardless of agency setting.
        foreach (\App\Models\PresentationVersion::SECTION_FLOOR as $floor) {
            $map[$floor] = true;
        }
        return $map;
    }

    /**
     * Short initials derived from the agency name — first letter of each
     * word, uppercased, capped at 3 characters.
     *
     *   "Home Finders Coastal" → "HFC"
     *   "Smith Realty"         → "SR"
     *   "Acme"                 → "A"
     *
     * Used as the second tier of the map H-pin fallback chain
     * (agency logo → initials → generic house glyph). Pure string
     * derivation; no DB read.
     */
    public function getInitialsAttribute(): string
    {
        $name = trim((string) ($this->name ?? ''));
        if ($name === '') return '';
        $parts = preg_split('/\s+/', $name) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            $first = mb_substr($part, 0, 1);
            if ($first === '') continue;
            $letters .= mb_strtoupper($first);
            if (mb_strlen($letters) >= 3) break;
        }
        return $letters;
    }

    /**
     * Sum of cost_zar in the unified AI cost ledger (ai_usage_events)
     * attributed to this agency for the given month (default = current month).
     *
     * Reads the ledger, not ai_narrative_cache, so the budget cap sees EVERY
     * AI surface — mobile voice, image analysis, DocuPerfect, marketing copy,
     * presentation evidence — not just the MIC narrative gateway. This is the
     * line that makes canMakeAiCall() honest (spec ai-cost-ledger.md §4.4).
     */
    public function aiBudgetUsedZar(?\Carbon\Carbon $month = null): float
    {
        $month ??= \Carbon\Carbon::now();
        return (float) \DB::table('ai_usage_events')
            ->where('agency_id', $this->id)
            ->whereBetween('occurred_at', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ])
            ->sum('cost_zar');
    }

    /**
     * Used / budget × 100. Returns 0 when budget is zero (avoid div-by-zero).
     */
    public function aiBudgetUsedPct(?\Carbon\Carbon $month = null): float
    {
        $budget = (float) ($this->ai_monthly_budget_zar ?? 0);
        if ($budget <= 0) return 0.0;
        return round(($this->aiBudgetUsedZar($month) / $budget) * 100, 2);
    }

    /**
     * ZAR remaining in the month (negative when overage allowed + exceeded).
     */
    public function aiBudgetRemaining(?\Carbon\Carbon $month = null): float
    {
        return (float) ($this->ai_monthly_budget_zar ?? 0) - $this->aiBudgetUsedZar($month);
    }

    /**
     * Budget status:
     *   healthy  — usage < warning_pct
     *   warning  — warning_pct ≤ usage < (warning_pct + critical-band-width). Default: 80–94.99%.
     *   critical — 95% ≤ usage < hard_cap_pct
     *   capped   — usage ≥ hard_cap_pct AND overage not allowed
     */
    public function aiBudgetStatus(?\Carbon\Carbon $month = null): string
    {
        $used = $this->aiBudgetUsedPct($month);
        $warn = (int) ($this->ai_budget_warning_pct ?? 80);
        $hard = (int) ($this->ai_budget_hard_cap_pct ?? 110);

        if ($used >= $hard && !$this->ai_budget_overage_allowed) {
            return self::AI_BUDGET_STATUS_CAPPED;
        }
        if ($used >= 95) {
            return self::AI_BUDGET_STATUS_CRITICAL;
        }
        if ($used >= $warn) {
            return self::AI_BUDGET_STATUS_WARNING;
        }
        return self::AI_BUDGET_STATUS_HEALTHY;
    }

    /**
     * Whether this agency may make further AI calls right now.
     * False only when status=capped (hard cap reached AND overage disallowed).
     */
    public function canMakeAiCall(?\Carbon\Carbon $month = null): bool
    {
        return $this->aiBudgetStatus($month) !== self::AI_BUDGET_STATUS_CAPPED;
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * AT-117 §4a — resolved outreach send-window config.
     *
     * The stored JSON merged OVER the legal defaults, so a partial/absent config
     * never yields a missing day key. NULL column → full defaults. Every consumer
     * (OutreachWindowService, the settings UI) reads through here — never the raw
     * column — so the default-merge happens in exactly one place.
     */
    public function outreachSendWindow(): array
    {
        $stored = is_array($this->outreach_send_window) ? $this->outreach_send_window : [];
        $resolved = self::OUTREACH_SEND_WINDOW_DEFAULT;
        foreach (self::OUTREACH_SEND_WINDOW_DAYS as $day) {
            if (isset($stored[$day]) && is_array($stored[$day])) {
                $resolved[$day] = array_merge($resolved[$day], array_intersect_key($stored[$day], $resolved[$day]));
            }
        }
        if (array_key_exists('public_holidays_off', $stored)) {
            $resolved['public_holidays_off'] = (bool) $stored['public_holidays_off'];
        }
        return $resolved;
    }

    /**
     * AT-117 §4a — the timezone the send-window is evaluated in. No per-agency
     * timezone column today; CoreX is single-region SA, so this is the app
     * timezone (Africa/Johannesburg, UTC+2, no DST). Centralised here so a future
     * per-agency timezone column is a one-line change, not a hunt across surfaces.
     */
    public function outreachTimezone(): string
    {
        return config('app.timezone') ?: 'Africa/Johannesburg';
    }

    /**
     * AT-117 §8 — the cutoff before which a SURFACED-but-unsent outreach-queue row
     * is stale (→ expired). With a configured expiry-hours, that's now − N hours;
     * NULL falls back to the sensible default: the start of today (end of the
     * surfaced day). Evaluated in the agency timezone. Centralised so the sweep
     * never hardcodes the policy.
     */
    public function outreachQueueExpiryCutoff(?\Carbon\Carbon $now = null): \Carbon\Carbon
    {
        $now = $now ? $now->copy()->setTimezone($this->outreachTimezone()) : \Carbon\Carbon::now($this->outreachTimezone());
        $hours = $this->outreach_queue_expiry_hours;
        return ($hours && $hours > 0)
            ? $now->copy()->subHours($hours)
            : $now->copy()->startOfDay();
    }

    public function defaultBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Website API keys (one per agency website). Spec: agency-public-api.md §3.5. */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(AgencyApiKey::class)->latest();
    }

    /**
     * True when this agency has at least one live website — i.e. an API key
     * that is neither revoked nor expired. Drives whether the public Website
     * tab/settings are shown. Mirrors the "active website" check used by
     * CompanySettingsController::pushSoldToWebsite().
     */
    public function hasActiveWebsite(): bool
    {
        return $this->apiKeys->contains(fn (AgencyApiKey $key) => $key->isActive());
    }

    /**
     * Active Admin users for this agency.
     * "Admin" = role string equal to 'admin' (per Role.name convention).
     * See .ai/specs/agency-admin-rule.md.
     */
    public function admins(): HasMany
    {
        return $this->hasMany(User::class)
            ->where('role', 'admin')
            ->where('is_active', 1);
    }

    public function adminCount(): int
    {
        return $this->admins()->count();
    }

    /**
     * True iff $user is the only active Admin for this agency.
     */
    public function accessRequests(): HasMany
    {
        return $this->hasMany(AgencyAccessRequest::class, 'target_agency_id');
    }

    public function requiresExternalAccessAuthorization(): bool
    {
        return (bool) $this->require_external_access_authorization;
    }

    public function hasSoleAdmin(User $user): bool
    {
        if ($user->role !== 'admin' || (int) $user->agency_id !== (int) $this->id) {
            return false;
        }
        return $this->adminCount() <= 1;
    }

    public function rmcpVersions(): HasMany
    {
        return $this->hasMany(RmcpVersion::class);
    }

    public function currentRmcpVersion(): HasOne
    {
        return $this->hasOne(RmcpVersion::class)->where('status', 'active');
    }

    public function complianceOfficer(): HasOne
    {
        return $this->hasOne(FicaOfficerAppointment::class)
            ->where('role', FicaOfficerAppointment::ROLE_PRIMARY)
            ->whereNull('ended_on');
    }

    // ── Information Officer (POPIA s55) ──

    public function informationOfficerAppointments(): HasMany
    {
        return $this->hasMany(InformationOfficerAppointment::class);
    }

    /** Returns the active primary IO's User (or null). */
    public function currentInformationOfficer(): ?\App\Models\User
    {
        return InformationOfficerAppointment::currentPrimary($this->id)?->user;
    }

    /** Returns active primary + deputies as a Collection of appointment rows. */
    public function allActiveInformationOfficers(): \Illuminate\Database\Eloquent\Collection
    {
        return InformationOfficerAppointment::where('agency_id', $this->id)
            ->whereNull('ended_on')
            ->orderByRaw("FIELD(role, '" . InformationOfficerAppointment::ROLE_PRIMARY . "', '" . InformationOfficerAppointment::ROLE_DEPUTY . "')")
            ->get();
    }

    // ── Privacy Policy (rebuild of Phase 9c-3 as a Company Settings field) ──

    /**
     * Lazy-generate a public token. Called when content is first saved
     * and persists across edits — agents may share the link in advance.
     * Token rotation is a future endpoint.
     */
    public function generatePrivacyPolicyToken(): string
    {
        do {
            $token = \Illuminate\Support\Str::random(48);
        } while (
            self::where('privacy_policy_token', $token)->exists()
            || \App\Models\Branch::where('privacy_policy_token', $token)->exists()
        );
        return $token;
    }

    /** Returns the public /legal/privacy/{token} URL when published, else null. */
    public function privacyPolicyPublicUrl(): ?string
    {
        if (!$this->privacy_policy_token || !$this->privacy_policy_published_at) {
            return null;
        }
        return route('public.privacy-policy', ['token' => $this->privacy_policy_token]);
    }

    /**
     * Cascade: internal published URL > external popi_url > null.
     * Use this everywhere `popi_url` was previously rendered so internal
     * hosting takes precedence once a privacy policy is published.
     */
    public function effectivePopiUrl(): ?string
    {
        return $this->privacyPolicyPublicUrl() ?: ($this->popi_url ?: null);
    }

    /**
     * AT-49 — the marketing email-signature footer to render at the bottom of
     * outgoing marketing email. Returns the agency's explicit override when set,
     * otherwise a sensible default that already carries the generic /unsubscribe
     * link, so the feature works out of the box with no configuration.
     */
    public function renderedMarketingUnsubscribeFooter(): string
    {
        $explicit = trim((string) $this->marketing_unsubscribe_footer);
        if ($explicit !== '') {
            return $explicit;
        }

        $url = $this->marketingUnsubscribeUrl();

        return "You're receiving marketing updates from {$this->name}. "
            . "To stop receiving these messages, unsubscribe here: {$url}";
    }

    /** The public, agency-scoped unsubscribe URL used in the marketing footer. */
    public function marketingUnsubscribeUrl(): string
    {
        return route('seller-outreach.public.unsubscribe.show', ['agency' => $this->id]);
    }

    /**
     * Branding for an UNAUTHENTICATED public page (opt-out / unsubscribe): the
     * agency's own logo + theme colours, reusing the same fields the public
     * website page (public/agency-properties) renders — never hardcoded. Safe
     * on public routes: looked up by id with the global scope off (no auth user).
     *
     * @return array{name:string, logoUrl:?string, colors:array{sidebar:string,icon:string,default:string,button:string}}
     */
    public static function publicBrandingFor(int $agencyId): array
    {
        $a = static::withoutGlobalScopes()->find($agencyId);

        return [
            'name'    => ($a && $a->name) ? (string) $a->name : 'our agency',
            'logoUrl' => ($a && $a->logo_path) ? asset('storage/' . $a->logo_path) : null,
            'colors'  => [
                'sidebar' => ($a && $a->sidebar_color) ? $a->sidebar_color : '#0b2a4a',
                'icon'    => ($a && $a->icon_color) ? $a->icon_color : '#33c4e0',
                'default' => ($a && $a->default_color) ? $a->default_color : '#0b2a4a',
                'button'  => ($a && $a->button_color) ? $a->button_color : '#00b4d8',
            ],
        ];
    }

    /**
     * AT-50 — which deals_v2 statuses count as a LIVE transaction for this
     * agency. Returns the agency override when set, else the system default
     * from config/corex-outreach.php. Always a non-empty list of strings.
     */
    public function liveDealStatuses(): array
    {
        $override = $this->outreach_live_deal_statuses;
        $statuses = is_array($override) && $override !== []
            ? $override
            : (array) config('corex-outreach.live_deal_statuses', ['active']);

        // Defensive: never return empty (would make every contact "not live").
        $statuses = array_values(array_filter(array_map('strval', $statuses), fn ($s) => $s !== ''));

        return $statuses !== [] ? $statuses : ['active'];
    }

    /**
     * AT-59 — ± window (minutes) for the time-based fallback match between an
     * ingested outbound message and a provisional click. Agency override, else
     * the config default. Always a positive integer.
     */
    public function reconcileWindowMinutes(): int
    {
        $minutes = (int) ($this->communication_reconcile_window_minutes
            ?? config('communications.reconcile_window_minutes', 2880));

        return max(1, $minutes);
    }

    /**
     * AT-59 — age (hours) after which an unreconciled provisional comm row is
     * soft-purged by communications:prune-provisional. Agency override, else the
     * config default. Always a positive integer.
     */
    public function provisionalPruneHours(): int
    {
        $hours = (int) ($this->communication_provisional_prune_hours
            ?? config('communications.provisional_prune_hours', 168));

        return max(1, $hours);
    }

    // ── Payroll ──

    public function payrollEmployees(): HasMany
    {
        return $this->hasMany(Payroll\PayrollEmployee::class);
    }

    public function payrollRuns(): HasMany
    {
        return $this->hasMany(Payroll\PayrollRun::class);
    }

    public function earningTypes(): HasMany
    {
        return $this->hasMany(Payroll\PayrollEarningType::class);
    }

    public function deductionTypes(): HasMany
    {
        return $this->hasMany(Payroll\PayrollDeductionType::class);
    }

    /**
     * Check if agency's total annual gross payroll meets the SDL threshold.
     * Based on last 12 months of finalised payroll runs vs current tax rebate data.
     */
    public function hasSdlObligation(): bool
    {
        $rebate = Payroll\PayrollTaxRebate::forTaxYear(now())->first();
        if (! $rebate) {
            return false;
        }

        $annualGross = $this->payrollRuns()
            ->finalised()
            ->where('period_month', '>=', now()->subMonths(12)->startOfMonth()->toDateString())
            ->sum('total_gross');

        return bccomp((string) $annualGross, (string) $rebate->sdl_threshold_annual, 2) >= 0;
    }

    // ── Leave ──

    public function leaveTypes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\LeaveType::class);
    }

    public function leaveApplications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\LeaveApplication::class);
    }

    public function leaveTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\LeaveTransaction::class);
    }

    public function staffTakeOnRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave\StaffTakeOnRecord::class);
    }

    // ── Per-agency maintenance mode (AT-93) ──────────────────────────────

    /**
     * Is this agency currently in maintenance? Enforced after login by
     * AgencyMaintenanceGate; System Owners bypass.
     */
    public function isInMaintenance(): bool
    {
        return (bool) $this->maintenance_mode;
    }

    /**
     * Put this agency into maintenance. Reversible state change — no delete.
     * Stamps the start time (for the "in maintenance since…" display) only on
     * the transition into maintenance, so re-enabling doesn't reset it.
     */
    public function enterMaintenance(?string $message = null): void
    {
        $this->forceFill([
            'maintenance_mode'       => true,
            'maintenance_message'    => $message ?: $this->maintenance_message,
            'maintenance_started_at' => $this->maintenance_started_at ?: now(),
        ])->save();
    }

    /**
     * Lift maintenance and restore normal access. Clears the start time.
     */
    public function exitMaintenance(): void
    {
        $this->forceFill([
            'maintenance_mode'       => false,
            'maintenance_started_at' => null,
        ])->save();
    }
}
