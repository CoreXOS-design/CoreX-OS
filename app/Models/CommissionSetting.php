<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToAgency;
class CommissionSetting extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'commission_split_agent',
        'commission_split_agency',
        'annual_cap',
        'post_cap_transaction_fee',
        'post_cap_fee_cap',
        'post_cap_reduced_fee',
        'monthly_platform_fee',
        'mentor_program_enabled',
        'mentor_extra_split',
        'mentor_transactions',
        'risk_management_fee',
        'risk_management_cap',
        'revenue_share_enabled',
        'revenue_share_pool_percent',
        'tier_1_percent',
        'tier_2_percent',
        'tier_3_percent',
        'tier_4_percent',
        'tier_5_percent',
        'tier_6_percent',
        'tier_7_percent',
        'tier_4_flqa_requirement',
        'tier_5_flqa_requirement',
        'tier_6_flqa_requirement',
        'tier_7_flqa_requirement',
    ];

    protected $casts = [
        'annual_cap' => 'decimal:2',
        'post_cap_transaction_fee' => 'decimal:2',
        'post_cap_fee_cap' => 'decimal:2',
        'post_cap_reduced_fee' => 'decimal:2',
        'monthly_platform_fee' => 'decimal:2',
        'risk_management_fee' => 'decimal:2',
        'risk_management_cap' => 'decimal:2',
        'mentor_program_enabled' => 'boolean',
        'revenue_share_enabled' => 'boolean',
        'tier_1_percent' => 'decimal:2',
        'tier_2_percent' => 'decimal:2',
        'tier_3_percent' => 'decimal:2',
        'tier_4_percent' => 'decimal:2',
        'tier_5_percent' => 'decimal:2',
        'tier_6_percent' => 'decimal:2',
        'tier_7_percent' => 'decimal:2',
    ];

    // ── Relationships ──

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    // ── Accessors ──

    /**
     * Get tier config as structured array (for revenue share engine).
     */
    public function getTierConfigAttribute(): array
    {
        return [
            1 => ['percent' => (float) $this->tier_1_percent, 'flqa_required' => 0],
            2 => ['percent' => (float) $this->tier_2_percent, 'flqa_required' => 0],
            3 => ['percent' => (float) $this->tier_3_percent, 'flqa_required' => 0],
            4 => ['percent' => (float) $this->tier_4_percent, 'flqa_required' => $this->tier_4_flqa_requirement],
            5 => ['percent' => (float) $this->tier_5_percent, 'flqa_required' => $this->tier_5_flqa_requirement],
            6 => ['percent' => (float) $this->tier_6_percent, 'flqa_required' => $this->tier_6_flqa_requirement],
            7 => ['percent' => (float) $this->tier_7_percent, 'flqa_required' => $this->tier_7_flqa_requirement],
        ];
    }

    // ── Static helpers ──

    /**
     * AT-253 (STANDARDS Rule 17) — the no-agency defaults.
     *
     * Mirrors the column defaults in the commission_settings migration. They live here so a
     * caller with NO agency context can still READ a coherent settings object without a row
     * being written; keep them in step with the migration if those defaults ever change.
     */
    private const NO_AGENCY_DEFAULTS = [
        'commission_split_agent'     => 80,
        'commission_split_agency'    => 20,
        'annual_cap'                 => 160000.00,
        'post_cap_transaction_fee'   => 2500.00,
        'post_cap_fee_cap'           => 50000.00,
        'post_cap_reduced_fee'       => 750.00,
        'monthly_platform_fee'       => 850.00,
        'mentor_program_enabled'     => true,
        'mentor_extra_split'         => 20,
        'mentor_transactions'        => 3,
        'risk_management_fee'        => 400.00,
        'risk_management_cap'        => 5000.00,
        'revenue_share_enabled'      => true,
        'revenue_share_pool_percent' => 50,
        'tier_1_percent'             => 3.50,
        'tier_2_percent'             => 4.00,
        'tier_3_percent'             => 2.50,
        'tier_4_percent'             => 1.50,
        'tier_5_percent'             => 1.00,
        'tier_6_percent'             => 0.50,
        'tier_7_percent'             => 0.25,
        'tier_4_flqa_requirement'    => 5,
        'tier_5_flqa_requirement'    => 10,
        'tier_6_flqa_requirement'    => 15,
        'tier_7_flqa_requirement'    => 20,
    ];

    /**
     * Get or create settings for an agency (singleton per agency).
     *
     * AT-253 (STANDARDS Rule 17) — NO AGENCY CONTEXT IS A REAL CONTEXT.
     *
     * An owner/super-admin has `agency_id = NULL`, and so do console commands, queued jobs
     * and webhooks. Callers coerce that to the sentinel 0. Without this guard, 0 falls
     * straight into `firstOrCreate(['agency_id' => 0])` — and agency 0 has no parent row, so
     * the insert violates the FK and 500s the page. Worse, the pre-existing workaround
     * (`?? 1` at the call sites) avoided the 500 by silently reading and MUTATING agency 1's
     * commission settings on behalf of a user who belongs to no agency: a wrong-tenant write
     * on money configuration.
     *
     * So a non-positive agency id returns an UNSAVED defaults instance: every read works and
     * nothing is persisted. This guard is what lets the call sites drop `?? 1` for `?: 0`
     * safely — the same contract AgencyContactSettings::forAgency() already honours.
     */
    public static function forAgency(int $agencyId): self
    {
        if ($agencyId <= 0) {
            return (new self())->forceFill(self::NO_AGENCY_DEFAULTS);
        }

        return static::firstOrCreate(['agency_id' => $agencyId]);
    }
}
