<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealPipelineCondition;
use App\Models\DealV2\DealPipelineConditionStep;

/**
 * AT-334 — the composable-pipeline catalogue: the common/base conveyancing spine + the
 * step pack each suspensive condition contributes + the movable Granted marker.
 *
 * SOURCE OF TRUTH — master template (Phase 1 of template-as-source).
 * The catalogue now READS its definition from the master pipeline template in the DB
 * (deal_pipeline_conditions + deal_pipeline_condition_steps, GLOBAL rows: agency_id NULL,
 * pipeline_template_id = MASTER_TEMPLATE_ID). `definition()` below is the canonical PHP
 * shape that Dr2PipelineCatalogSeeder writes into those tables — and the behaviour-
 * preserving FALLBACK the reader uses when the tables are un-seeded, so no environment is
 * ever left with an empty catalogue. Composition (option-driven expansion, grant
 * convergence, __grant__ resolution, ordering) stays procedural and runs on whichever
 * source is active, so DB-backed and code-backed output are identical by construction
 * (proven by scripts/dr2-catalog-equivalence — the seed IS the fallback definition).
 *
 * A step def:
 *   key, name, follows(step key|null|__grant__), offset(days), milestone, suspensive,
 *   grant_marker, completion(type), condition(key|null), anchor(bool — first step,
 *   auto-completed from deals.deal_date), pos(order hint), deps(AND-gate keys),
 *   manual_due(captured by-when date → the step's manual Due).
 */
class Dr2ConditionCatalog
{
    /** Global master template sentinel (Phase 1 = one shared definition for every agency). */
    public const MASTER_TEMPLATE_ID = 0;

    /** The base-spine group key inside the definition (not a user-selectable condition). */
    public const BASE_KEY = '__base__';

    /** User-selectable conditions, in display order. */
    public const CONDITION_ORDER = ['bond', 'cash', 'sale_of_another'];

    /** Per-request cache of the active definition (DB if seeded, else the code fallback). */
    private ?array $activeDefinition = null;

    // ─────────────────────────────────────────────────────────────────────────────
    // Public API (unchanged contract)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Conditions a deal can carry, with their option schema (for the Structure tab).
     * Read from the master template; falls back to the code definition when un-seeded.
     */
    public function conditions(): array
    {
        $def = $this->activeDefinition();
        $out = [];
        foreach (self::CONDITION_ORDER as $key) {
            if (! isset($def['conditions'][$key])) {
                continue;
            }
            $out[$key] = [
                'label'   => $def['conditions'][$key]['label'],
                'options' => $def['conditions'][$key]['options'] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Resolve the full ordered step set for a deal's condition selections.
     * $selections = ['bond'=>['deposit'=>true], 'cash'=>['payments'=>2], 'sale_of_another'=>[]]
     */
    public function resolve(array $selections): array
    {
        return $this->composeFrom($this->activeDefinition(), $selections);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Composition — procedural, runs on ANY definition (DB or code). This IS resolve().
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Compose the ordered step defs from a definition + selections. Identical logic for
     * the DB-backed and code-backed definition, which is what makes them equivalent.
     */
    public function composeFrom(array $def, array $selections): array
    {
        $steps          = $this->baseStepsFrom($def);
        $suspensiveKeys = [];

        foreach ($selections as $key => $opts) {
            if ($key === self::BASE_KEY || ! isset($def['conditions'][$key])) {
                continue;
            }
            foreach ($this->conditionStepsFrom($def, $key, is_array($opts) ? $opts : []) as $s) {
                $steps[] = $s;
                if (! empty($s['suspensive'])) {
                    $suspensiveKeys[] = $s['key'];
                }
            }
        }

        // Granted marker CONVERGES on EVERY active suspensive condition — primary follows =
        // the last suspensive step, the rest are AND-gate deps. No suspensive → follows OTP.
        $grantFollows = end($suspensiveKeys) ?: 'otp';
        $grantDeps    = array_values(array_diff($suspensiveKeys, [$grantFollows]));
        $steps[] = ['key' => 'granted', 'name' => 'Granted', 'follows' => $grantFollows, 'deps' => $grantDeps, 'offset' => 0, 'milestone' => true, 'grant_marker' => true, 'completion' => 'manual_tick', 'pos' => 30];

        // Resolve the __grant__ sentinel (base steps that start after grant → follow the marker).
        foreach ($steps as &$s) {
            if (($s['follows'] ?? null) === '__grant__') {
                $s['follows'] = 'granted';
            }
        }
        unset($s);

        usort($steps, fn ($a, $b) => ($a['pos'] ?? 999) <=> ($b['pos'] ?? 999));
        return $steps;
    }

    /** The common spine (final-shape step defs), taken from the definition's base group. */
    private function baseStepsFrom(array $def): array
    {
        return array_map(
            fn ($t) => $this->finalizeStep($t),
            $def['conditions'][self::BASE_KEY]['steps'] ?? [],
        );
    }

    /**
     * The step pack for one condition, expanded for its options. Option-driven inclusion
     * (deposit / proof-of-funds) and payment fan-out stay procedural; a captured by-when
     * date on the mapped option seeds the step's manual Due.
     */
    private function conditionStepsFrom(array $def, string $key, array $opts): array
    {
        $templates = $def['conditions'][$key]['steps'] ?? [];
        $date = fn ($v) => (is_string($v) && $v !== '') ? $v : null;
        $out  = [];

        foreach ($templates as $t) {
            // Inclusion gates.
            if (! empty($t['requires_option']) && empty($opts[$t['requires_option']])) {
                continue;
            }
            if (! empty($t['requires_funds_mode'])) {
                $mode = (($opts['funds_mode'] ?? 'available') === 'proof_later') ? 'proof_later' : 'available';
                if ($mode !== $t['requires_funds_mode']) {
                    continue;
                }
            }

            // Payment fan-out — one template row → N payment steps, each with its own by-when date.
            if (($t['expand'] ?? null) === 'payments') {
                $n    = max(1, (int) ($opts['payments'] ?? 1));
                $dues = is_array($opts['payment_dues'] ?? null) ? $opts['payment_dues'] : [];
                $base = (int) ($t['pos'] ?? 90);
                for ($i = 1; $i <= $n; $i++) {
                    $row = $this->finalizeStep($t);
                    $row['key']        = "payment_{$i}";
                    $row['name']       = $n > 1 ? "Payment Received ({$i} of {$n})" : 'Payment Received';
                    $row['pos']        = $base + $i;
                    $row['manual_due'] = $date($dues[$i] ?? null);
                    $out[] = $row;
                }
                continue;
            }

            $row = $this->finalizeStep($t);
            if (! empty($t['manual_due_option'])) {
                $row['manual_due'] = $date($opts[$t['manual_due_option']] ?? null);
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Strip the definition-only marker keys, leaving a clean step def for composition. */
    private function finalizeStep(array $t): array
    {
        $keep = ['key', 'name', 'follows', 'offset', 'milestone', 'suspensive', 'grant_marker',
                 'completion', 'condition', 'anchor', 'status_trigger', 'deps', 'pos'];
        $row = [];
        foreach ($keep as $k) {
            if (array_key_exists($k, $t)) {
                $row[$k] = $t[$k];
            }
        }
        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Definition sourcing — DB (master template) with the code definition as fallback.
    // ─────────────────────────────────────────────────────────────────────────────

    /** The active definition: the DB master template if seeded, else the code definition. */
    public function activeDefinition(): array
    {
        if ($this->activeDefinition !== null) {
            return $this->activeDefinition;
        }
        return $this->activeDefinition = ($this->loadFromDb() ?? $this->definition());
    }

    /**
     * Reconstruct the definition from the GLOBAL master template rows, or null when
     * un-seeded. Global reference data → read via the sanctioned shared-row escape hatch
     * (agency_id IS NULL; see BelongsToAgency / multi-tenancy.md §2a).
     */
    public function loadFromDb(): ?array
    {
        $conds = DealPipelineCondition::queryWithoutAgencyScope()
            ->whereNull('agency_id')
            ->where('pipeline_template_id', self::MASTER_TEMPLATE_ID)
            ->get();

        if ($conds->isEmpty()) {
            return null;
        }

        $stepsByCond = DealPipelineConditionStep::queryWithoutAgencyScope()
            ->whereNull('agency_id')
            ->whereIn('condition_id', $conds->pluck('id'))
            ->orderBy('id')   // insertion order = the definition order (final output re-sorts by pos)
            ->get()
            ->groupBy('condition_id');

        $conditions = [];
        foreach ($conds as $cond) {
            $isBase = $cond->key === self::BASE_KEY;
            $conditions[$cond->key] = [
                'label'   => $cond->label,
                'options' => $isBase ? null : ($cond->options_schema ?? []),
                'steps'   => ($stepsByCond[$cond->id] ?? collect())
                    ->map(fn ($r) => $this->rowToTemplate($r, $cond->key))
                    ->all(),
            ];
        }

        return ['conditions' => $conditions];
    }

    /** Rebuild one step-def template array from a stored condition-step row. */
    private function rowToTemplate(DealPipelineConditionStep $r, string $groupKey): array
    {
        $t = [
            'key'        => $r->step_key,
            'name'       => $r->name,
            'follows'    => $r->follows_key,          // null for the anchor
            'offset'     => (int) $r->days_offset,
            'completion' => $r->completion_type,
            'pos'        => (int) $r->position,
        ];
        if ($groupKey !== self::BASE_KEY)  { $t['condition'] = $groupKey; }
        if ($r->is_milestone)              { $t['milestone'] = true; }
        if ($r->is_suspensive)             { $t['suspensive'] = true; }
        if ($r->is_anchor)                 { $t['anchor'] = true; }
        if ($r->status_trigger)            { $t['status_trigger'] = $r->status_trigger; }
        if (! empty($r->deps_keys))        { $t['deps'] = $r->deps_keys; }
        if ($r->manual_due_option)         { $t['manual_due_option'] = $r->manual_due_option; }
        if ($r->requires_option)           { $t['requires_option'] = $r->requires_option; }
        if ($r->requires_funds_mode)       { $t['requires_funds_mode'] = $r->requires_funds_mode; }
        if ($r->expand)                    { $t['expand'] = $r->expand; }
        return $t;
    }

    /**
     * The canonical composable-pipeline definition — the single source both the seeder
     * writes to the master template AND the reader falls back to when un-seeded.
     * (Was the hardcoded conditions()/baseSteps()/conditionSteps() arrays.)
     */
    public function definition(): array
    {
        return [
            'conditions' => [
                self::BASE_KEY => [
                    'label'   => 'Base spine',
                    'options' => null,
                    'steps'   => [
                        ['key' => 'otp',           'name' => 'Deal Signed',                 'follows' => null,        'offset' => 0,  'milestone' => true, 'completion' => 'date_input',      'anchor' => true, 'pos' => 10],
                        ['key' => 'attorneys',     'name' => 'Attorneys Instructed',        'follows' => '__grant__', 'offset' => 3,  'completion' => 'text_input',      'pos' => 40],
                        ['key' => 'fica_buyer',    'name' => 'FICA Completed (Buyer)',      'follows' => 'attorneys', 'offset' => 7,  'completion' => 'document_upload', 'pos' => 45],
                        ['key' => 'fica_seller',   'name' => 'FICA Completed (Seller)',     'follows' => 'fica_buyer','offset' => 3,  'completion' => 'document_upload', 'pos' => 46],
                        ['key' => 'elec_coc',      'name' => 'Electrical COC',              'follows' => 'attorneys', 'offset' => 14, 'completion' => 'document_upload', 'pos' => 50],
                        ['key' => 'beetle',        'name' => 'Beetle Certificate',          'follows' => 'attorneys', 'offset' => 14, 'completion' => 'document_upload', 'pos' => 51],
                        ['key' => 'rates',         'name' => 'Rates Clearance',             'follows' => 'attorneys', 'offset' => 21, 'completion' => 'document_upload', 'pos' => 55],
                        ['key' => 'docs_signed',   'name' => 'Documents Signed',            'follows' => 'attorneys', 'offset' => 5,  'completion' => 'document_signed', 'pos' => 60],
                        ['key' => 'transfer_duty', 'name' => 'Transfer Duty / SARS Receipt','follows' => 'docs_signed','offset' => 7, 'completion' => 'document_upload', 'pos' => 65],
                        ['key' => 'lodgement',     'name' => 'Deeds Office Lodgement',      'follows' => 'rates',     'deps' => ['fica_seller', 'elec_coc', 'beetle', 'transfer_duty'], 'offset' => 5, 'milestone' => true, 'completion' => 'date_input', 'pos' => 70],
                        ['key' => 'registration',  'name' => 'Registration / Transfer',     'follows' => 'lodgement', 'offset' => 10, 'milestone' => true, 'completion' => 'date_input', 'status_trigger' => 'completed', 'pos' => 80],
                    ],
                ],
                'bond' => [
                    'label'   => 'Bond',
                    'options' => [
                        'deposit'     => ['type' => 'bool', 'label' => 'Include a deposit', 'default' => false],
                        'bond_due'    => ['type' => 'date', 'label' => 'Bond due by', 'default_offset' => 30],
                        'deposit_due' => ['type' => 'date', 'label' => 'Deposit due by'],
                    ],
                    'steps' => [
                        ['key' => 'bond_app',      'name' => 'Bond Application Submitted', 'follows' => 'otp',      'offset' => 3,  'completion' => 'date_input',   'condition' => 'bond', 'pos' => 20],
                        ['key' => 'bond_approved', 'name' => 'Bond Approved',              'follows' => 'bond_app', 'offset' => 21, 'milestone' => true, 'suspensive' => true, 'completion' => 'date_input', 'condition' => 'bond', 'manual_due_option' => 'bond_due', 'pos' => 21],
                        ['key' => 'guarantees',    'name' => 'Guarantees Issued',          'follows' => 'bond_approved', 'offset' => 10, 'completion' => 'text_input', 'condition' => 'bond', 'pos' => 48],
                        ['key' => 'deposit',       'name' => 'Deposit Paid',               'follows' => 'otp',      'offset' => 3,  'completion' => 'amount_input', 'condition' => 'bond', 'manual_due_option' => 'deposit_due', 'requires_option' => 'deposit', 'pos' => 19],
                    ],
                ],
                'cash' => [
                    'label'   => 'Cash',
                    'options' => [
                        'funds_mode'   => ['type' => 'enum', 'label' => 'Funds', 'values' => ['available', 'proof_later'], 'default' => 'available'],
                        'proof_due'    => ['type' => 'date', 'label' => 'Proof of funds by'],
                        'payments'     => ['type' => 'int', 'label' => 'How many payments?', 'default' => 1, 'min' => 1, 'max' => 6],
                        'payment_dues' => ['type' => 'date_list', 'label' => 'Payment due by'],
                    ],
                    'steps' => [
                        ['key' => 'proof_funds', 'name' => 'Proof of Funds', 'follows' => 'otp', 'offset' => 3, 'milestone' => true, 'suspensive' => true, 'completion' => 'amount_input', 'condition' => 'cash', 'manual_due_option' => 'proof_due', 'requires_funds_mode' => 'proof_later', 'pos' => 22],
                        ['key' => 'payment',     'name' => 'Payment Received', 'follows' => 'lodgement', 'offset' => 0, 'completion' => 'amount_input', 'condition' => 'cash', 'manual_due_option' => 'payment_dues', 'expand' => 'payments', 'pos' => 90],
                    ],
                ],
                'sale_of_another' => [
                    'label'   => 'Subject to sale of another property',
                    'options' => ['property_sold_due' => ['type' => 'date', 'label' => 'Property sold by']],
                    'steps'   => [
                        ['key' => 'linked_sold', 'name' => 'Linked Property Sold', 'follows' => 'otp', 'offset' => 0, 'milestone' => true, 'suspensive' => true, 'completion' => 'date_input', 'condition' => 'sale_of_another', 'manual_due_option' => 'property_sold_due', 'pos' => 23],
                    ],
                ],
            ],
        ];
    }
}
