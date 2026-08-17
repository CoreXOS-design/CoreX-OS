<?php

namespace App\Models\Payroll;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollDeductionType extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'code',
        'label',
        'sars_source_code',
        'is_statutory',
        'is_system',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_statutory' => 'boolean',
        'is_system'    => 'boolean',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    /**
     * SA-standard default deduction types provisioned for every agency (spec §10.4).
     * Single source of truth: the PayrollDeductionTypeSeeder delegates here, the
     * AgencyObserver seeds these on agency creation, and `payroll:seed-default-types`
     * backfills existing agencies. Keyed by `code` for firstOrCreate idempotency.
     */
    public const DEFAULTS = [
        ['code' => 'paye',                'label' => 'PAYE',            'sars_source_code' => '4102', 'is_statutory' => true,  'is_system' => true,  'sort_order' => 1, 'is_active' => true],
        ['code' => 'uif_employee',        'label' => 'UIF',             'sars_source_code' => '4141', 'is_statutory' => true,  'is_system' => true,  'sort_order' => 2, 'is_active' => true],
        ['code' => 'cellphone_deduction', 'label' => 'Cellphone',       'sars_source_code' => null,   'is_statutory' => false, 'is_system' => false, 'sort_order' => 3, 'is_active' => true],
        ['code' => 'loan_repayment',      'label' => 'Loan Repayment',  'sars_source_code' => null,   'is_statutory' => false, 'is_system' => false, 'sort_order' => 4, 'is_active' => true],
        ['code' => 'garnishee',           'label' => 'Garnishee Order', 'sars_source_code' => null,   'is_statutory' => false, 'is_system' => false, 'sort_order' => 5, 'is_active' => true],
    ];

    /**
     * Provision this agency's default deduction types. Idempotent — firstOrCreate on
     * (agency_id, code) never duplicates or overwrites an existing row. Returns
     * ['created' => int, 'existing' => int]. See PayrollEarningType::seedDefaultsFor()
     * for the agency_id / withoutEvents contract.
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

    public function employeeDeductions(): HasMany
    {
        return $this->hasMany(PayrollEmployeeDeduction::class, 'deduction_type_id');
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
        if ($this->is_system || $this->is_statutory) {
            abort(403, 'System or statutory deduction types cannot be deleted. Deactivate instead.');
        }

        return parent::delete();
    }
}
