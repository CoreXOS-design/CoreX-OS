<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
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
        'group_id',
        // set on the five property-sales ROLE rows only (transfer_attorney, bond_attorney, conveyancer, bond_originator, external_agency): the stored supplier `specialty`
        // value that row stands for. A role row is never ticked on a supplier and never offered in the work-order / pipeline dropdowns.
        'legacy_specialty',
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

    /** Active WORK types - what the pipeline step config, work orders, supplier ticks and the fault match use. The property-sales role rows are not work types. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('legacy_specialty');
    }

    /** Every work type, active or not (role rows excluded). */
    public function scopeWork($query)
    {
        return $query->whereNull('legacy_specialty');
    }

    /** What the supplier Trade dropdown offers: active work types AND the active property-sales role rows. */
    public function scopeOffered($query)
    {
        return $query->where('is_active', true);
    }

    public function isRole(): bool
    {
        return $this->legacy_specialty !== null;
    }

    public function group()
    {
        return $this->belongsTo(AgencyServiceTypeGroup::class, 'group_id')->withTrashed();
    }

    /** The five property-sales roles, as stored on a supplier (`specialty`). */
    public const ROLES = [
        'transfer_attorney' => 'Transfer Attorney', 'bond_attorney' => 'Bond Attorney', 'conveyancer' => 'Conveyancer',
        'bond_originator' => 'Bond Originator', 'external_agency' => 'External Agency',
    ];

    /**
     * Seed the default list for one agency, idempotently. Called from the
     * migration backfill and the AgencyObserver (new agencies). Uses the raw
     * write path (explicit agency_id) so it is safe from any acting-agency hook.
     */
    public static function seedDefaultsFor(int $agencyId): void
    {
        // A from-scratch migrate runs the original create_agency_service_types backfill BEFORE the groups migration exists:
        // seed the plain list then (no groups, no roles); the groups migration adds both for every agency afterwards.
        if (! Schema::hasTable('agency_service_type_groups') || ! Schema::hasColumn('agency_service_types', 'group_id')) {
            foreach (self::DEFAULTS as $i => $row) {
                static::withoutGlobalScopes()->firstOrCreate(
                    ['agency_id' => $agencyId, 'code' => $row['code']],
                    ['label' => $row['label'], 'sort_order' => $i + 1, 'is_active' => true],
                );
            }

            return;
        }

        AgencyServiceTypeGroup::seedDefaultsFor($agencyId);
        $trades = AgencyServiceTypeGroup::tradesIdFor($agencyId);
        $sales = (int) AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $agencyId)->where('code', AgencyServiceTypeGroup::CODE_PROPERTY_SALES)->value('id');

        foreach (self::DEFAULTS as $i => $row) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'code' => $row['code']],
                ['label' => $row['label'], 'sort_order' => $i + 1, 'is_active' => true, 'group_id' => $trades],
            );
        }
        $sort = count(self::DEFAULTS);
        foreach (self::ROLES as $code => $label) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'code' => $code],
                ['label' => $label, 'sort_order' => ++$sort, 'is_active' => true, 'group_id' => $sales, 'legacy_specialty' => $code],
            );
        }
    }

    /**
     * §17 — the distinctive tokens of a COC name: lowercased words with the
     * generic certificate noise ("coc", "certificate", "compliance", …) stripped,
     * so "Electrical COC" → [electrical] and "Electric Fence COC" → [electric, fence].
     * Lets a service type be matched to its pipeline step by the noun that
     * actually differentiates it, not the shared "COC/Certificate" tail.
     */
    public static function distinctiveTokens(string $text): array
    {
        $noise = ['coc', 'certificate', 'cert', 'compliance', 'of', 'the', 'and', 'entomologist'];
        $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff($words, $noise));
    }

    /**
     * §17 — best-matching step from a collection of DealStepInstance for THIS type
     * (highest distinctive-token overlap, ≥1). Used to N/A the un-ticked COCs and
     * to attach a ticked COC's work order to its own pipeline step.
     */
    public function matchStep($stepInstances): ?object
    {
        $mine = self::distinctiveTokens($this->label . ' ' . $this->code);
        if (empty($mine)) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($stepInstances as $step) {
            $score = count(array_intersect($mine, self::distinctiveTokens((string) $step->name)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $step;
            }
        }

        return $bestScore >= 1 ? $best : null;
    }

    protected static function booted(): void
    {
        // A new agency-added type gets a stable code slugged from its label
        // (unless one was supplied). Kept ≤40 chars to fit service_type columns.
        static::creating(function (AgencyServiceType $t) {
            if (empty($t->code)) {
                $t->code = Str::limit(Str::slug($t->label, '_'), 40, '');
            }
            // every service type belongs to a group: when none is given it falls into the agency's "Your trades"
            if (empty($t->group_id) && $t->agency_id) {
                $t->group_id = AgencyServiceTypeGroup::tradesIdFor((int) $t->agency_id);
            }
        });
    }
}
