<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealCondition;
use App\Models\DealV2\DealStepInstance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AT-334 Phase 2 — assemble a deal's pipeline from its chosen suspensive conditions:
 * base spine + each active condition's step pack + the Granted marker, with follows
 * (trigger_step_instance_id) resolved and dates cascaded.
 *
 * GUARDRAIL: by default REFUSES to run on a deal that already has step instances, so
 * existing (old-model) deals are never rewritten. $force is for Restructure (Phase 6),
 * which recomposes deliberately (and preserves completed steps — handled there).
 */
class DealStructureAssembler
{
    public function __construct(
        private readonly Dr2ConditionCatalog $catalog,
        private readonly DealDateCascade $cascade,
    ) {}

    public function hasPipeline(Deal $deal): bool
    {
        // DR2 pipeline instances are DR1-anchored via dr1_deal_id (deal_id/deals_v2 stays null).
        return DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')->exists();
    }

    /**
     * @param array<string,array> $selections e.g. ['bond'=>['deposit'=>true],'cash'=>['payments'=>2]]
     */
    public function assemble(Deal $deal, array $selections, bool $force = false): void
    {
        if (! $force && $this->hasPipeline($deal)) {
            throw new \DomainException('This deal already has a pipeline. Use Restructure to change it.');
        }

        $agencyId = (int) $deal->agency_id;                 // parent-derived (P1 lesson: never rely on auto-stamp)
        $userId   = Auth::id();
        $defs     = $this->catalog->resolve($selections);

        DB::transaction(function () use ($deal, $selections, $agencyId, $userId, $defs) {
            // 1. Per-deal conditions (the audit-bearing state).
            DealCondition::where('deal_id', $deal->id)->delete(); // soft-delete prior active set (fresh assemble)
            foreach ($selections as $key => $opts) {
                if (! array_key_exists($key, $this->catalog->conditions())) {
                    continue;
                }
                DealCondition::create([
                    'deal_id'   => $deal->id,
                    'agency_id' => $agencyId,
                    'key'       => $key,
                    'status'    => 'active',
                    'options'   => is_array($opts) ? $opts : [],
                ]);
            }

            // 2. Step instances.
            $keyToId = [];
            $pos = 0;
            foreach ($defs as $d) {
                $isAnchor = ! empty($d['anchor']);
                $inst = DealStepInstance::create([
                    'deal_id'          => null,          // DR1-anchored (legacy deals_v2 pointer stays null)
                    'dr1_deal_id'      => $deal->id,
                    'agency_id'        => $agencyId,
                    'pipeline_step_id' => null,          // catalogue-driven, not a template row
                    'name'             => $d['name'],
                    'position'         => $pos += 10,
                    'is_locked'        => false,
                    'is_milestone'     => ! empty($d['milestone']),
                    'is_custom'        => false,
                    'is_suspensive'    => ! empty($d['suspensive']),
                    'is_grant_marker'  => ! empty($d['grant_marker']),
                    'condition_key'    => $d['condition'] ?? null,
                    'completion_type'  => $d['completion'] ?? 'manual_tick',
                    'status'           => $isAnchor ? 'completed' : 'not_started',
                    'trigger_type'     => ! empty($d['follows']) ? 'after_step' : 'on_creation',
                    'days_offset'      => (int) ($d['offset'] ?? 0),
                    'rag_green_days'   => 14,
                    'rag_amber_days'   => 7,
                    'rag_red_days'     => 3,
                    'current_rag'      => 'grey',
                    'notify_agent'     => true,
                    'notify_bm'        => true,
                    'notify_admin'     => false,
                    'status_trigger'   => $d['status_trigger'] ?? null,
                    'requires_bm_approval' => false,
                    'approval_status'  => 'not_required',
                    // Anchor auto-completes from the Deal Register date — no re-capture.
                    'completed_at'     => $isAnchor ? now() : null,
                    'actual_date'      => $isAnchor ? $deal->deal_date : null,
                    'completed_by_id'  => $isAnchor ? $userId : null,
                ]);
                $keyToId[$d['key']] = $inst->id;
            }

            // 3. Resolve follows → trigger_step_instance_id.
            foreach ($defs as $d) {
                if (! empty($d['follows']) && isset($keyToId[$d['follows']], $keyToId[$d['key']])) {
                    DealStepInstance::where('id', $keyToId[$d['key']])
                        ->update(['trigger_step_instance_id' => $keyToId[$d['follows']]]);
                }
            }

            // Point the deal at "no template" — new-model deals are catalogue-driven.
            if ($deal->deal_pipeline_template_id !== null && $force) {
                // leave existing template pointer alone on force/restructure
            }
        });

        // 4. Cascade the Due dates off the anchor + follows chain.
        $deal->refresh();
        $this->cascade->recompute($deal);
    }
}
