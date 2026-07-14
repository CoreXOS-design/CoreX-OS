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
