<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealPipelineTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AT-158 WS-R1 — Default pipeline-template provisioning.
 *
 * ONE write-path for the three shipped default templates (Standard Bond Sale,
 * Cash Sale, Sale of Second Property). Consumed by BOTH the deploy seeder
 * (DealPipelineTemplateSeeder) and the in-app "Load standard templates"
 * affordance on Pipeline Setup.
 *
 * IDEMPOTENT + ADDITIVE + AGENCY-SAFE (Non-Negotiable #1 — no hard deletes):
 *   - Templates are matched per (agency_id, name, deal_type) and created only
 *     when absent. An existing template is NEVER deleted, replaced, or
 *     force-recreated. Re-running never duplicates and never destroys an
 *     agency's customised templates or the deal step-instances that reference
 *     their steps.
 *   - Steps are seeded ONLY when the template currently has zero steps (a fresh
 *     template). A template a user has already customised is left untouched.
 *   - `is_default` is set only when the agency has no existing default for that
 *     deal_type, so provisioning never steals the default flag from an
 *     agency's own template.
 *
 * This replaces the previous seeder's agency-blind
 * `DealPipelineStep::query()->forceDelete()` + `DealPipelineTemplate::query()
 * ->forceDelete()` — a cross-agency hard-delete landmine that was wired into
 * scripts/deploy.sh and would have wiped every agency's templates on the next
 * deploy (AT-158 capture-parity investigation, .ai/audits/2026-07-04-...).
 */
class DealPipelineTemplateProvisioner
{
    /**
     * Provision the default templates for a single agency.
     *
     * @return array{created:int, skipped:int, steps_created:int, names:array<int,string>}
     */
    public function provisionDefaultsForAgency(int $agencyId, ?int $createdById = null): array
    {
        $created = 0;
        $skipped = 0;
        $stepsCreated = 0;
        $names = [];

        // created_by_id is NOT-NULL with an FK to users. Guarantee a valid
        // creator for every input path (including the console seeder passing
        // null): fall back to an admin/first user of the agency. If the system
        // genuinely has no users to attribute to, fail with a CLEAR message
        // rather than letting a raw SQLSTATE FK/NOT-NULL violation surface
        // (BUILD_STANDARD §4).
        $createdById = $createdById ?: $this->resolveCreatorId($agencyId);
        if (!$createdById) {
            throw new \RuntimeException('Cannot load standard pipeline templates: no user found to attribute them to.');
        }

        foreach ($this->definitions() as $def) {
            [$wasCreated, $tplStepsCreated, $template] = DB::transaction(
                fn () => $this->provisionOne($agencyId, $createdById, $def)
            );

            $wasCreated ? $created++ : $skipped++;
            $stepsCreated += $tplStepsCreated;
            $names[] = $template->name;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'steps_created' => $stepsCreated,
            'names' => $names,
        ];
    }

    /**
     * WS-V5 — UPGRADE an agency's shipped default templates to the current
     * (corrected) definitions WITHOUT hard-deleting anything. Only a default
     * template that is NOT referenced by any deal is soft-deleted (steps too) and
     * re-provisioned fresh; a template in use, or one an agency has customised
     * under a different name, is never touched. Safe/idempotent to re-run.
     *
     * @return array{refreshed:int, skipped_in_use:int} + provision result keys
     */
    public function refreshDefaultsForAgency(int $agencyId, ?int $createdById = null): array
    {
        $refreshed = 0;
        $skippedInUse = 0;

        foreach ($this->definitions() as $def) {
            $meta = $def['meta'];
            $existing = DealPipelineTemplate::where('agency_id', $agencyId)
                ->where('name', $meta['name'])
                ->where('deal_type', $meta['deal_type'])
                ->first();

            if (! $existing) {
                continue; // nothing to refresh — provisioning below will create it
            }

            $inUse = \App\Models\DealV2\DealV2::withoutGlobalScopes()
                ->where('pipeline_template_id', $existing->id)
                ->exists();
            if ($inUse) {
                $skippedInUse++;
                continue; // preserve a template that live deals depend on
            }

            DB::transaction(function () use ($existing) {
                $existing->steps()->delete(); // SoftDeletes — audit-preserved, never hard-deleted
                $existing->delete();
            });
            $refreshed++;
        }

        $provision = $this->provisionDefaultsForAgency($agencyId, $createdById);

        return array_merge($provision, ['refreshed' => $refreshed, 'skipped_in_use' => $skippedInUse]);
    }

    /**
     * Resolve a non-null user id to stamp as creator: prefer an admin of the
     * agency, then any user of the agency, then the first admin/user anywhere.
     * created_by_id is NOT-NULL, so this must always return a valid id.
     */
    private function resolveCreatorId(int $agencyId): ?int
    {
        return User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_admin', true)->value('id')
            ?? User::withoutGlobalScopes()->where('agency_id', $agencyId)->value('id')
            ?? User::withoutGlobalScopes()->where('is_admin', true)->value('id')
            ?? User::withoutGlobalScopes()->value('id');
    }

    /**
     * @return array{0:bool,1:int,2:DealPipelineTemplate}  [wasCreated, stepsCreated, template]
     */
    private function provisionOne(int $agencyId, ?int $createdById, array $def): array
    {
        $meta = $def['meta'];

        // Match per (agency_id, name, deal_type). Explicit agency_id filter is
        // deterministic in every context: request queries are already scoped to
        // the agency; console/owner-bypass queries see all agencies but the
        // explicit where() pins us to the target agency.
        $template = DealPipelineTemplate::where('agency_id', $agencyId)
            ->where('name', $meta['name'])
            ->where('deal_type', $meta['deal_type'])
            ->first();

        $wasCreated = false;

        if (!$template) {
            $hasDefaultForType = DealPipelineTemplate::where('agency_id', $agencyId)
                ->where('deal_type', $meta['deal_type'])
                ->where('is_default', true)
                ->exists();

            $template = DealPipelineTemplate::create([
                'agency_id' => $agencyId,
                'name' => $meta['name'],
                'deal_type' => $meta['deal_type'],
                'branch_id' => null,
                // Only claim default when the agency has none for this type yet.
                'is_default' => $meta['is_default'] && !$hasDefaultForType,
                'is_active' => true,
                'created_by_id' => $createdById,
            ]);

            $wasCreated = true;
        }

        // Additive: seed steps only for a fresh (stepless) template. Never
        // replace an already-populated / customised template's steps.
        $stepsCreated = 0;
        if ($template->steps()->count() === 0) {
            $stepsCreated = $this->createSteps(
                $template, $def['steps'], $def['dependencies'] ?? [], $def['suspensive'] ?? []
            );
        }

        return [$wasCreated, $stepsCreated, $template];
    }

    /**
     * Create the ordered steps for a template with a two-pass trigger-link
     * resolve (a step's after_step trigger references a sibling by name).
     */
    private function createSteps(DealPipelineTemplate $template, array $steps, array $dependencies = [], array $suspensive = []): int
    {
        $stepMap = [];

        foreach ($steps as [$position, $name, $isLocked, $isMilestone, $completionType, $triggerType, $triggerStepName, $daysOffset, $ragGreen, $ragAmber, $ragRed, $statusTrigger, $negativeStatusTrigger, $negativeOutcomeLabel, $requiresBmApproval]) {
            $step = $template->steps()->create([
                'agency_id' => $template->agency_id,
                'name' => $name,
                'position' => $position,
                'is_locked' => $isLocked,
                'is_milestone' => $isMilestone,
                'completion_type' => $completionType,
                'trigger_type' => $triggerType,
                'trigger_step_id' => null,
                'days_offset' => $daysOffset,
                // AT-158 WS7 — two-threshold RAG: green is derived ("not yet
                // amber"), so no seeded green threshold. rag_green_days keeps its
                // column default. $ragGreen in the tuple is retained but unused.
                'rag_amber_days' => $ragAmber,
                'rag_red_days' => $ragRed,
                'status_trigger' => $statusTrigger,
                'negative_status_trigger' => $negativeStatusTrigger,
                'negative_outcome_label' => $negativeOutcomeLabel,
                'requires_bm_approval' => $requiresBmApproval,
            ]);

            $stepMap[$name] = $step;
        }

        foreach ($steps as [$position, $name, $isLocked, $isMilestone, $completionType, $triggerType, $triggerStepName, $daysOffset, $ragGreen, $ragAmber, $ragRed, $statusTrigger, $negativeStatusTrigger, $negativeOutcomeLabel, $requiresBmApproval]) {
            if ($triggerStepName && isset($stepMap[$triggerStepName])) {
                $stepMap[$name]->update([
                    'trigger_step_id' => $stepMap[$triggerStepName]->id,
                ]);
            }
        }

        // WS-V1 — additional AND-gate dependencies declared as
        // ['Dependent Step' => ['Predecessor A', 'Predecessor B', ...]].
        // These are predecessors BEYOND the single primary trigger above; a step
        // activates only when its primary trigger AND all of these complete.
        $depRows = [];
        foreach ($dependencies as $dependentName => $predecessorNames) {
            $dependent = $stepMap[$dependentName] ?? null;
            if (! $dependent) {
                continue;
            }
            foreach ((array) $predecessorNames as $predName) {
                $pred = $stepMap[$predName] ?? null;
                if (! $pred || $pred->id === $dependent->id) {
                    continue; // unknown or self
                }
                $depRows[] = [
                    'agency_id' => $template->agency_id,
                    'pipeline_step_id' => $dependent->id,
                    'depends_on_step_id' => $pred->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if ($depRows) {
            DB::table('deal_pipeline_step_dependencies')->insert($depRows);
        }

        // WS-V2 — flag suspensive-condition steps (by name). The deal moves to
        // Granted only when ALL suspensive steps complete (AND-gate).
        foreach ($suspensive as $suspName) {
            if (isset($stepMap[$suspName])) {
                $stepMap[$suspName]->update(['is_suspensive' => true]);
            }
        }

        return count($steps);
    }

    /**
     * The three shipped default templates — corrected against the canonical SA
     * conveyancing process (AT-158 WS-V5; reference:
     * .ai/audits/2026-07-05-sa-conveyancing-canonical-reference.md).
     *
     * Corrections vs the original seed: Deposit sequenced from OTP (not from bond
     * grant); added Bond Cancellation Figures, Guarantees Issued, Documents
     * Signed, Transfer Duty / SARS Receipt, and Levy / HOA Consent; Water
     * Installation COC dropped from the KZN default (Cape-Town-only); Beetle
     * present on cash too (coastal seller obligation). Bond Approved (+ Deposit)
     * flagged SUSPENSIVE so the deal moves to Granted only when ALL are complete
     * (WS-V2); Deeds Office Lodgement AND-gated on the full preparation cluster
     * so it never counts down before every certificate/clearance/guarantee is in
     * (WS-V1). Every offset is a RELATIVE default measured from its predecessor's
     * completion and is agency-configurable.
     *
     * Tuple order:
     * [position, name, is_locked, is_milestone, completion_type, trigger_type,
     *  trigger_step_name, days_offset, rag_green(unused), rag_amber, rag_red,
     *  status_trigger, negative_status_trigger, negative_outcome_label,
     *  requires_bm_approval]
     * Plus per-template 'suspensive' (step names) and 'dependencies'
     * (dependent => [extra predecessors], AND-gate beyond the primary trigger).
     *
     * @return array<int,array{meta:array,steps:array,suspensive?:array,dependencies?:array}>
     */
    public function definitions(): array
    {
        return [
            [
                'meta' => ['name' => 'Standard Bond Sale', 'deal_type' => 'bond', 'is_default' => true],
                'steps' => [
                    [1,  'OTP Signed',                  true,  true,  'date_input',       'on_creation', null,                          0,  14, 7,  3,  null,        'cancelled', 'OTP Rejected',   false],
                    [2,  'Deposit Paid',                true,  false, 'amount_input',     'after_step',  'OTP Signed',                  3,  10, 5,  2,  null,        null,        null,             false],
                    [3,  'Bond Application Submitted',  false, false, 'date_input',       'after_step',  'OTP Signed',                  3,  10, 5,  2,  null,        null,        null,             false],
                    [4,  'Bond Approved',               true,  true,  'date_input',       'after_step',  'Bond Application Submitted',  21, 15, 7,  3,  'granted',   'declined',  'Bond Declined',  false],
                    [5,  'Attorneys Instructed',        false, false, 'text_input',       'after_step',  'Bond Approved',               3,  10, 5,  2,  null,        null,        null,             false],
                    [6,  'Bond Cancellation Figures',   false, false, 'text_input',       'after_step',  'Attorneys Instructed',        5,  10, 5,  2,  null,        null,        null,             false],
                    [7,  'FICA Completed (Buyer)',      true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',        7,  10, 5,  2,  null,        null,        null,             false],
                    [8,  'FICA Completed (Seller)',     true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',        7,  10, 5,  2,  null,        null,        null,             false],
                    [9,  'Guarantees Issued',           true,  false, 'text_input',       'after_step',  'Bond Approved',               10, 14, 7,  3,  null,        null,        null,             false],
                    [10, 'Electrical COC',              true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,             false],
                    [11, 'Beetle Certificate',          true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,             false],
                    [12, 'Gas COC',                     false, false, 'document_upload',  'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,             false],
                    [13, 'Electric Fence COC',          false, false, 'document_upload',  'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,             false],
                    [14, 'Rates Clearance',             true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',        21, 21, 10, 5,  null,        null,        null,             false],
                    [15, 'Levy / HOA Consent',          false, false, 'document_upload',  'after_step',  'Attorneys Instructed',        21, 21, 10, 5,  null,        null,        null,             false],
                    [16, 'Documents Signed',            true,  false, 'document_signed',  'after_step',  'Guarantees Issued',           3,  10, 5,  2,  null,        null,        null,             false],
                    [17, 'Transfer Duty / SARS Receipt',true,  false, 'document_upload',  'after_step',  'Documents Signed',            7,  10, 5,  2,  null,        null,        null,             false],
                    [18, 'Deeds Office Lodgement',      true,  true,  'date_input',       'after_step',  'Rates Clearance',             5,  10, 5,  2,  null,        null,        null,             false],
                    [19, 'Registration',                true,  true,  'date_input',       'after_step',  'Deeds Office Lodgement',      10, 10, 5,  2,  'completed', null,        null,             false],
                ],
                'suspensive' => ['Bond Approved', 'Deposit Paid'],
                'dependencies' => [
                    'Documents Signed'       => ['FICA Completed (Buyer)', 'FICA Completed (Seller)'],
                    'Deeds Office Lodgement' => ['Documents Signed', 'Electrical COC', 'Beetle Certificate', 'Guarantees Issued', 'Transfer Duty / SARS Receipt'],
                ],
            ],
            [
                'meta' => ['name' => 'Cash Sale', 'deal_type' => 'cash', 'is_default' => false],
                'steps' => [
                    [1, 'OTP Signed',                  true,  true,  'date_input',       'on_creation', null,                     0,  14, 7, 3,  null,        'cancelled', 'OTP Rejected', false],
                    [2, 'Deposit Paid',                true,  false, 'amount_input',     'after_step',  'OTP Signed',             3,  10, 5, 2,  'granted',   null,        null,           false],
                    [3, 'Attorneys Instructed',        false, false, 'text_input',       'after_step',  'OTP Signed',             5,  10, 5, 2,  null,        null,        null,           false],
                    [4, 'FICA Completed (Buyer)',      true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',   7,  10, 5, 2,  null,        null,        null,           false],
                    [5, 'FICA Completed (Seller)',     true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',   7,  10, 5, 2,  null,        null,        null,           false],
                    [6, 'Electrical COC',              true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',   14, 14, 7, 3,  null,        null,        null,           false],
                    [7, 'Beetle Certificate',          true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',   14, 14, 7, 3,  null,        null,        null,           false],
                    [8, 'Rates Clearance',             true,  false, 'document_upload',  'after_step',  'Attorneys Instructed',   21, 21, 10,5,  null,        null,        null,           false],
                    [9, 'Documents Signed',            true,  false, 'document_signed',  'after_step',  'Attorneys Instructed',   5,  10, 5, 2,  null,        null,        null,           false],
                    [10,'Transfer Duty / SARS Receipt',true,  false, 'document_upload',  'after_step',  'Documents Signed',       7,  10, 5, 2,  null,        null,        null,           false],
                    [11,'Deeds Office Lodgement',      true,  true,  'date_input',       'after_step',  'Rates Clearance',        5,  10, 5, 2,  null,        null,        null,           false],
                    [12,'Registration',                true,  true,  'date_input',       'after_step',  'Deeds Office Lodgement', 10, 10, 5, 2,  'completed', null,        null,           false],
                ],
                'suspensive' => ['Deposit Paid'],
                'dependencies' => [
                    'Documents Signed'       => ['FICA Completed (Buyer)', 'FICA Completed (Seller)'],
                    'Deeds Office Lodgement' => ['Documents Signed', 'Electrical COC', 'Beetle Certificate', 'Transfer Duty / SARS Receipt'],
                ],
            ],
            [
                'meta' => ['name' => 'Sale of Second Property', 'deal_type' => 'sale_of_2nd', 'is_default' => false],
                'steps' => [
                    [1,  'OTP Signed',                  true,  true,  'date_input',            'on_creation', null,                          0,  14, 7,  3,  null,        'cancelled', 'OTP Rejected',       false],
                    [2,  'Linked Property Sold',        true,  true,  'auto_from_linked_deal', 'manual',      null,                          0,  14, 7,  3,  null,        'cancelled', 'Linked Sale Failed', false],
                    [3,  'Deposit Paid',                true,  false, 'amount_input',          'after_step',  'OTP Signed',                  3,  10, 5,  2,  null,        null,        null,                 false],
                    [4,  'Bond Application Submitted',  false, false, 'date_input',            'after_step',  'Linked Property Sold',        3,  10, 5,  2,  null,        null,        null,                 false],
                    [5,  'Bond Approved',               true,  true,  'date_input',            'after_step',  'Bond Application Submitted',  21, 15, 7,  3,  'granted',   'declined',  'Bond Declined',      false],
                    [6,  'Attorneys Instructed',        false, false, 'text_input',            'after_step',  'Bond Approved',               3,  10, 5,  2,  null,        null,        null,                 false],
                    [7,  'Bond Cancellation Figures',   false, false, 'text_input',            'after_step',  'Attorneys Instructed',        5,  10, 5,  2,  null,        null,        null,                 false],
                    [8,  'FICA Completed (Buyer)',      true,  false, 'document_upload',       'after_step',  'Attorneys Instructed',        7,  10, 5,  2,  null,        null,        null,                 false],
                    [9,  'FICA Completed (Seller)',     true,  false, 'document_upload',       'after_step',  'Attorneys Instructed',        7,  10, 5,  2,  null,        null,        null,                 false],
                    [10, 'Guarantees Issued',           true,  false, 'text_input',            'after_step',  'Bond Approved',               10, 14, 7,  3,  null,        null,        null,                 false],
                    [11, 'Electrical COC',              true,  false, 'document_upload',       'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,                 false],
                    [12, 'Beetle Certificate',          true,  false, 'document_upload',       'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,                 false],
                    [13, 'Gas COC',                     false, false, 'document_upload',       'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,                 false],
                    [14, 'Electric Fence COC',          false, false, 'document_upload',       'after_step',  'Attorneys Instructed',        14, 14, 7,  3,  null,        null,        null,                 false],
                    [15, 'Rates Clearance',             true,  false, 'document_upload',       'after_step',  'Attorneys Instructed',        21, 21, 10, 5,  null,        null,        null,                 false],
                    [16, 'Levy / HOA Consent',          false, false, 'document_upload',       'after_step',  'Attorneys Instructed',        21, 21, 10, 5,  null,        null,        null,                 false],
                    [17, 'Documents Signed',            true,  false, 'document_signed',       'after_step',  'Guarantees Issued',           3,  10, 5,  2,  null,        null,        null,                 false],
                    [18, 'Transfer Duty / SARS Receipt',true,  false, 'document_upload',       'after_step',  'Documents Signed',            7,  10, 5,  2,  null,        null,        null,                 false],
                    [19, 'Deeds Office Lodgement',      true,  true,  'date_input',            'after_step',  'Rates Clearance',             5,  10, 5,  2,  null,        null,        null,                 false],
                    [20, 'Registration',                true,  true,  'date_input',            'after_step',  'Deeds Office Lodgement',      10, 10, 5,  2,  'completed', null,        null,                 false],
                ],
                'suspensive' => ['Bond Approved', 'Deposit Paid'],
                'dependencies' => [
                    'Documents Signed'       => ['FICA Completed (Buyer)', 'FICA Completed (Seller)'],
                    'Deeds Office Lodgement' => ['Documents Signed', 'Electrical COC', 'Beetle Certificate', 'Guarantees Issued', 'Transfer Duty / SARS Receipt'],
                ],
            ],
        ];
    }
}
