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
     * Get or create settings for an agency (singleton per agency).
     */
    public static function forAgency(int $agencyId): self
    {
        return static::firstOrCreate(['agency_id' => $agencyId]);
    }
}
