<?php

namespace App\Models;

use App\Models\Compliance\FicaOfficerAppointment;
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
        'fic_no',
        'sidebar_color',
        'icon_color',
        'default_color',
        'button_color',
        'ai_voice_enabled',
        'ai_image_recognition_enabled',
        'logo_path',
        'email_disclaimer',
        'popi_url',
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
        'pp_enabled',
        'pp_username',
        'pp_password',
        'pp_branch_guid',
        'pp_wsdl',
        'pp_sandbox',
        'pp_image_base_url',
        'pp_webhook_secret',
        'pp_last_sync_error',
        'pp_locations_synced_at',
        'pp_locations_last_error',
        'default_branch_id',
        'whistleblow_approver_user_ids',
        'whistleblow_compliance_officer_email',
        'whistleblow_tier_recipients',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'ai_voice_enabled' => 'boolean',
        'ai_image_recognition_enabled' => 'boolean',
        'require_external_access_authorization' => 'boolean',
        'split_branches_enabled' => 'boolean',
        'default_branch_id' => 'integer',
        'p24_password' => 'encrypted',
        'p24_enabled' => 'boolean',
        'p24_locations_synced_at' => 'datetime',
        'pp_enabled' => 'boolean',
        'pp_sandbox' => 'boolean',
        'pp_password' => 'encrypted',
        'pp_webhook_secret' => 'encrypted',
        'pp_locations_synced_at' => 'datetime',
        'whistleblow_approver_user_ids' => 'array',
        'whistleblow_tier_recipients' => 'array',
    ];

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
