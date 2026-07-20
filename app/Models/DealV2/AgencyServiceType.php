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
        });
    }
}
