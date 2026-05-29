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

    protected $fillable = [
        'name',
        'slug',
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
        'sidebar_color',
        'icon_color',
        'default_color',
        'button_color',
        'logo_path',
        'email_disclaimer',
        'popi_url',
        'privacy_policy_markdown',
        'privacy_policy_token',
        'privacy_policy_published_at',
        'whatsapp_launch_mode_agent',
        'whatsapp_launch_mode_seller',
        'prospecting_pitch_temp_lock_minutes',
        'is_active',
        'is_demo',
        'require_external_access_authorization',
        'dashboard_settings_mode',
        'split_branches_enabled',
        'p24_agency_id',
        'p24_agency_label',
        'p24_username',
        'p24_password',
        'p24_user_group_id',
        'p24_enabled',
        'p24_locations_synced_at',
        'p24_last_sync_error',
        'default_branch_id',
        'whistleblow_approver_user_ids',
        'whistleblow_compliance_officer_email',
        'whistleblow_tier_recipients',
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
        // Build 5 — freshness window for public share CTAs.
        'presentations_freshness_days',
        // Build 8b — independent CMA compute engine cleaning controls.
        'cma_compute_recency_months',
        'cma_compute_iqr_multiplier',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'privacy_policy_published_at' => 'datetime',
        'require_external_access_authorization' => 'boolean',
        'split_branches_enabled' => 'boolean',
        'default_branch_id' => 'integer',
        'p24_password' => 'encrypted',
        'p24_enabled' => 'boolean',
        'p24_locations_synced_at' => 'datetime',
        'whistleblow_approver_user_ids' => 'array',
        'whistleblow_tier_recipients' => 'array',
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
        // Build 5.
        'presentations_freshness_days'                  => 'integer',
        // Build 8b.
        'cma_compute_recency_months'                    => 'integer',
        'cma_compute_iqr_multiplier'                    => 'decimal:2',
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

    public const AI_BUDGET_STATUS_HEALTHY  = 'healthy';
    public const AI_BUDGET_STATUS_WARNING  = 'warning';
    public const AI_BUDGET_STATUS_CRITICAL = 'critical';
    public const AI_BUDGET_STATUS_CAPPED   = 'capped';

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
     * Sum of cost_zar in ai_narrative_cache attributed to this agency
     * for the given month (default = current month).
     */
    public function aiBudgetUsedZar(?\Carbon\Carbon $month = null): float
    {
        $month ??= \Carbon\Carbon::now();
        return (float) \DB::table('ai_narrative_cache')
            ->where('agency_id', $this->id)
            ->whereBetween('generated_at', [
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

    public function defaultBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
}
