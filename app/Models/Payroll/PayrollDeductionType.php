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
