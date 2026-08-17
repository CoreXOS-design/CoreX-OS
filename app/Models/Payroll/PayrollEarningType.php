<?php

namespace App\Models\Payroll;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollEarningType extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'code',
        'label',
        'sars_source_code',
        'is_taxable',
        'is_fringe_benefit',
        'affects_uif_remuneration',
        'affects_sdl_remuneration',
        'pro_rates_on_partial',
        'sort_order',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'is_taxable'              => 'boolean',
        'is_fringe_benefit'       => 'boolean',
        'affects_uif_remuneration' => 'boolean',
        'affects_sdl_remuneration' => 'boolean',
        'pro_rates_on_partial'    => 'boolean',
        'is_system'               => 'boolean',
        'is_active'               => 'boolean',
        'sort_order'              => 'integer',
    ];

    /**
     * SA-standard default earning types provisioned for every agency (spec §10.3).
     * Single source of truth: the PayrollEarningTypeSeeder delegates here, the
     * AgencyObserver seeds these on agency creation, and `payroll:seed-default-types`
     * backfills existing agencies. Keyed by `code` for firstOrCreate idempotency.
     */
    public const DEFAULTS = [
        ['code' => 'basic',                 'label' => 'Basic Salary',          'sars_source_code' => '3601', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'pro_rates_on_partial' => true, 'sort_order' => 1, 'is_system' => true,  'is_active' => true],
        ['code' => 'bonus',                 'label' => 'Bonus',                 'sars_source_code' => '3605', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 2, 'is_system' => false, 'is_active' => true],
        ['code' => 'overtime',              'label' => 'Overtime',              'sars_source_code' => '3607', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 3, 'is_system' => false, 'is_active' => true],
        ['code' => 'cell_allowance',        'label' => 'Cell Allowance',        'sars_source_code' => '3713', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 4, 'is_system' => false, 'is_active' => true],
        ['code' => 'fuel_allowance',        'label' => 'Fuel Allowance',        'sars_source_code' => '3713', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 5, 'is_system' => false, 'is_active' => true],
        ['code' => 'travel_allowance_fixed','label' => 'Travel Allowance',      'sars_source_code' => '3701', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 6, 'is_system' => false, 'is_active' => true],
        ['code' => 'travel_reimbursive',    'label' => 'Reimbursive Travel',    'sars_source_code' => '3703', 'is_taxable' => false, 'is_fringe_benefit' => false, 'affects_uif_remuneration' => false, 'affects_sdl_remuneration' => false, 'sort_order' => 7, 'is_system' => false, 'is_active' => true],
        ['code' => 'subsistence',           'label' => 'Subsistence',           'sars_source_code' => '3714', 'is_taxable' => false, 'is_fringe_benefit' => false, 'affects_uif_remuneration' => false, 'affects_sdl_remuneration' => false, 'sort_order' => 8, 'is_system' => false, 'is_active' => true],
        ['code' => 'commission_earnings',   'label' => 'Commission (tax-only)', 'sars_source_code' => '3606', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 9, 'is_system' => true,  'is_active' => true],
    ];

    /**
     * Provision this agency's default earning types. Idempotent — firstOrCreate on
     * (agency_id, code) never duplicates or overwrites an existing row. Returns
     * ['created' => int, 'existing' => int].
     *
     * agency_id is set explicitly. A WEB-request caller (AgencyObserver on agency
     * creation) MUST invoke this inside Model::withoutEvents() so BelongsToAgency's
     * `creating` hook cannot override agency_id to the acting admin's own agency.
     * Console callers (payroll:seed-default-types) have no Auth::user(), so the hook
     * is a no-op and the explicit agency_id is honoured.
     */
    public static function seedDefaultsFor(int $agencyId): array
    {
        $created = 0;
        foreach (self::DEFAULTS as $type) {
            $row = static::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'code' => $type['code']],
                $type
            );
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return ['created' => $created, 'existing' => count(self::DEFAULTS) - $created];
    }

    // ── Relationships ──

    public function employeeEarnings(): HasMany
    {
        return $this->hasMany(PayrollEmployeeEarning::class, 'earning_type_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }

    // ── Guards ──

    public function delete()
    {
        if ($this->is_system) {
            abort(403, 'System earning types cannot be deleted. Deactivate instead.');
        }

        return parent::delete();
    }
}
