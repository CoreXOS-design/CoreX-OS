<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * AT-229 — an agency's own COC / service-type list for supplier work orders.
 *
 * Replaces the hardcoded {COC, Beetle, Gas, …} dropdown that lived in the
 * pipeline step-config UI. Each agency configures its own list (add / edit /
 * soft-delete); the work-order "service type" dropdown reads from it.
 *
 * `code` is the STABLE value stored on a work order (deal_pipeline_step_work_orders
 * .service_type and deal_step_work_orders.service_type) — renaming a label never
 * breaks an existing configured step. `label` is what the agent sees.
 */
class AgencyServiceType extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'code',
        'label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The historical hardcoded set — every agency starts here so nothing breaks.
     * Order matters (seeded sort_order). `code` MUST match the values that were
     * previously hardcoded in the step-config dropdown.
     */
    public const DEFAULTS = [
        ['code' => 'COC',           'label' => 'Electrical COC'],
        ['code' => 'Beetle',        'label' => 'Beetle / Entomologist'],
        ['code' => 'Gas',           'label' => 'Gas Certificate'],
        ['code' => 'Electric Fence','label' => 'Electric Fence COC'],
        ['code' => 'Plumbing',      'label' => 'Plumbing'],
        ['code' => 'Other',         'label' => 'Other'],
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Seed the default list for one agency, idempotently. Called from the
     * migration backfill and the AgencyObserver (new agencies). Uses the raw
     * write path (explicit agency_id) so it is safe from any acting-agency hook.
     */
    public static function seedDefaultsFor(int $agencyId): void
    {
        foreach (self::DEFAULTS as $i => $row) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'code' => $row['code']],
                ['label' => $row['label'], 'sort_order' => $i + 1, 'is_active' => true],
            );
        }
    }

    protected static function booted(): void
    {
        // A new agency-added type gets a stable code slugged from its label
        // (unless one was supplied). Kept ≤40 chars to fit service_type columns.
        static::creating(function (AgencyServiceType $t) {
            if (empty($t->code)) {
                $t->code = Str::limit(Str::slug($t->label, '_'), 40, '');
            }
        });
    }
}
