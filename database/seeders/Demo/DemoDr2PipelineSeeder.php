<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Support\Facades\DB;

/**
 * Johan, 2026-09-02 — "why are we parking DR2 and pipelines? that's gold to show."
 *
 * DR2's own pipeline engine (App\Services\Deal\Dr1PipelineService, AT-216) has been
 * fully built and working since it landed — it is just never been USED on any demo
 * deal. All 76 existing demo deals (DemoDealsSeeder, deal_no 900000-900075) are
 * synthetic HISTORIC SALE COMPS: every one already carries a registration_date, none
 * carries a deal_pipeline_template_id, and deal_user (commission splits) is empty for
 * all of them. That is correct for what THOSE rows are for (CMA comparables) — they
 * are untouched here — but it means the pipeline board has never had anything to
 * show on a fresh demo.
 *
 * This seeder adds a SEPARATE batch of deals, in a distinct deal_no range
 * (920000+, well clear of DemoDealsSeeder's 900000-900999 reservation), each one
 * driven through the REAL Dr1PipelineService — the exact engine PipelineController
 * uses — so status derivation (accepted_status / granted_at / registration_date),
 * RAG, and the deal's pipeline pointer are all genuine, not faked. Deals stop at a
 * range of realistic points (just-accepted through fully-registered) so the board
 * shows the whole product, not just the end state.
 *
 * Idempotent: identified by file_no LIKE 'DR2-PIPELINE-DEMO/%' (a marker no other
 * seeder uses — DemoDealsSeeder's comps use 'DEMO/2024/N'). Every re-run soft-deletes
 * (never hard-deletes) its own prior batch — deals, deal_user, deal_contacts,
 * deal_step_instances, deal_logs, deal_step_instance_dependencies — then rebuilds
 * fresh, inside one DB transaction so a crash mid-run rolls back instead of leaving
 * a half-built batch (the CalendarDemoSeeder lesson, same fix applied here).
 */
final class DemoDr2PipelineSeeder
{
    private const FILE_NO_PREFIX = 'DR2-PIPELINE-DEMO/';
    private const DEAL_NO_BASE = 920000;

    /** Bond Sale template step names, in order, up to and including the named step. */
    private const BOND_STEPS_JUST_ACCEPTED = ['OTP Signed'];
    private const BOND_STEPS_IN_BOND = ['OTP Signed', 'Deposit Paid', 'Bond Application Submitted'];
    private const BOND_STEPS_GRANTED_COMPLIANCE = [
        'OTP Signed', 'Deposit Paid', 'Bond Application Submitted', 'Bond Approved',
        'Attorneys Instructed', 'Bond Cancellation Figures', 'Guarantees Issued', 'Electrical COC',
    ];
    private const BOND_STEPS_AWAITING_TRANSFER = [
        'OTP Signed', 'Deposit Paid', 'Bond Application Submitted', 'Bond Approved',
        'Attorneys Instructed', 'Bond Cancellation Figures', 'Guarantees Issued', 'Electrical COC',
        'Beetle Certificate', 'Gas COC', 'Electric Fence COC', 'Rates Clearance', 'Levy / HOA Consent',
        'Documents Signed', 'Transfer Duty / SARS Receipt',
    ];
    private const BOND_STEPS_ALL = [
        'OTP Signed', 'Deposit Paid', 'Bond Application Submitted', 'Bond Approved',
        'Attorneys Instructed', 'Bond Cancellation Figures', 'Guarantees Issued', 'Electrical COC',
        'Beetle Certificate', 'Gas COC', 'Electric Fence COC', 'Rates Clearance', 'Levy / HOA Consent',
        'Documents Signed', 'Transfer Duty / SARS Receipt', 'Deeds Office Lodgement', 'Registration',
    ];

    private const CASH_STEPS_JUST_ACCEPTED = ['OTP Signed'];
    private const CASH_STEPS_IN_PROGRESS = ['OTP Signed', 'Deposit Paid', 'Attorneys Instructed'];
    private const CASH_STEPS_AWAITING_TRANSFER = [
        'OTP Signed', 'Deposit Paid', 'Attorneys Instructed', 'Electrical COC', 'Beetle Certificate',
        'Rates Clearance', 'Documents Signed', 'Transfer Duty / SARS Receipt',
    ];
    private const CASH_STEPS_ALL = [
        'OTP Signed', 'Deposit Paid', 'Attorneys Instructed', 'Electrical COC', 'Beetle Certificate',
        'Rates Clearance', 'Documents Signed', 'Transfer Duty / SARS Receipt', 'Deeds Office Lodgement',
        'Registration',
    ];

    /**
     * One row per deal to build: [deal_type, template_name, steps_to_complete, days_since_deal_date].
     * days_since_deal_date is how far in the PAST deal_date sits — deeper pipeline
     * position gets an older deal_date, so "time in stage" reads as genuine, not
     * every deal dated today.
     */
    private const PLAN = [
        // ── Just accepted (OTP only) ──
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_JUST_ACCEPTED, 3],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_JUST_ACCEPTED, 5],
        ['cash', 'Cash Sale', self::CASH_STEPS_JUST_ACCEPTED, 2],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_JUST_ACCEPTED, 1],

        // ── In bond / due-diligence stage ──
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_IN_BOND, 12],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_IN_BOND, 18],
        ['cash', 'Cash Sale', self::CASH_STEPS_IN_PROGRESS, 10],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_IN_BOND, 15],

        // ── Granted / suspensive-condition & compliance stage ──
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_GRANTED_COMPLIANCE, 28],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_GRANTED_COMPLIANCE, 35],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_GRANTED_COMPLIANCE, 25],

        // ── Awaiting transfer (docs signed, at the Deeds Office queue) ──
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_AWAITING_TRANSFER, 55],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_AWAITING_TRANSFER, 62],
        ['cash', 'Cash Sale', self::CASH_STEPS_AWAITING_TRANSFER, 40],
        ['sale_of_2nd', 'Sale of Second Property', self::BOND_STEPS_AWAITING_TRANSFER, 58],

        // ── Registered (full pipeline complete) ──
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_ALL, 95],
        ['bond', 'Standard Bond Sale', self::BOND_STEPS_ALL, 110],
        ['cash', 'Cash Sale', self::CASH_STEPS_ALL, 75],
        ['cash', 'Cash Sale', self::CASH_STEPS_ALL, 88],
        ['sale_of_2nd', 'Sale of Second Property', self::BOND_STEPS_ALL, 130],
    ];

    public function run(int $agencyId): array
    {
        return DB::transaction(function () use ($agencyId) {
            $this->archivePriorBatch($agencyId);

            $branches = DB::table('branches')->where('agency_id', $agencyId)->whereNull('deleted_at')->get();
            $agentsByBranch = DB::table('users')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereIn('role', ['agent', 'branch_manager'])->get()->groupBy('branch_id');
            $buyers = DB::table('contacts')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->where('is_buyer', 1)->get(['id', 'first_name', 'last_name']);
            $sellers = DB::table('contacts')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->where('is_buyer', 0)->get(['id', 'first_name', 'last_name']);
            // Properties never already linked to ANY deal (comps or this batch) — avoids
            // Dr1PipelineService's granted-uniqueness gate ever tripping on a shared property.
            $usedPropertyIds = DB::table('deals')->whereNotNull('property_id')->pluck('property_id');
            $properties = DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereNotIn('id', $usedPropertyIds)
                ->whereIn('status', ['active', 'available'])
                ->inRandomOrder()->limit(count(self::PLAN) + 5)
                ->get(['id', 'address', 'suburb', 'price', 'branch_id']);

            if ($branches->isEmpty() || $agentsByBranch->isEmpty() || $buyers->isEmpty()
                || $sellers->isEmpty() || $properties->count() < count(self::PLAN)) {
                return ['inserted' => 0, 'note' => 'Insufficient branches/agents/contacts/properties to seed the DR2 pipeline demo.'];
            }

            $service = app(Dr1PipelineService::class);
            $templateIds = DB::table('deal_pipeline_templates')->where('agency_id', $agencyId)
                ->whereNull('deleted_at')->pluck('id', 'name');

            $propertyPool = $properties->values();
            $nextDealNo = self::DEAL_NO_BASE;
            $created = [];

            foreach (self::PLAN as $i => [$dealType, $templateName, $stepsToComplete, $ageDays]) {
                $templateId = $templateIds[$templateName] ?? null;
                if (! $templateId) {
                    continue; // template genuinely absent on this agency — skip, never invent one.
                }

                $property = $propertyPool[$i] ?? $propertyPool->random();
                $branch = $branches->firstWhere('id', $property->branch_id) ?? $branches->random();
                $branchAgents = $agentsByBranch->get($branch->id) ?: $agentsByBranch->flatten();
                $listingAgent = $branchAgents->random();
                // ~40% double-ended (same agent both sides), else a second branch agent as
                // the selling-side co-agent — real commission-split variety either way.
                $sellingAgent = (random_int(1, 100) <= 40 || $branchAgents->count() < 2)
                    ? $listingAgent
                    : $branchAgents->where('id', '!=', $listingAgent->id)->random();

                $buyer = $buyers->random();
                $seller = $sellers->random();

                $propertyValue = (int) ($property->price ?: random_int(900_000, 4_500_000));
                $commissionPct = [5.0, 6.0, 7.0, 7.5][array_rand([5.0, 6.0, 7.0, 7.5])];
                $totalCommissionIncVat = round($propertyValue * ($commissionPct / 100) * 1.15, 2);

                $dealDate = now()->subDays($ageDays);

                $deal = Deal::create([
                    'agency_id'       => $agencyId,
                    'deal_no'         => $nextDealNo++,
                    'file_no'         => self::FILE_NO_PREFIX . ($i + 1),
                    'period'          => $dealDate->format('Y-m'),
                    'deal_date'       => $dealDate->toDateString(),
                    'deal_type'       => $dealType,
                    'branch_id'       => $branch->id,
                    'property_id'     => $property->id,
                    'property_address'=> trim($property->address . ', ' . $property->suburb),
                    'property_value'  => $propertyValue,
                    'total_commission'=> $totalCommissionIncVat,
                    'seller_name'     => trim($seller->first_name . ' ' . $seller->last_name),
                    'buyer_name'      => trim($buyer->first_name . ' ' . $buyer->last_name),
                    'attorney_name'   => 'Nkosi & Partners Conveyancing',
                    'listing_split_percent' => 50,
                    'selling_split_percent' => 50,
                    'listing_our_share_percent' => 100,
                    'selling_our_share_percent' => 100,
                    'is_demo'         => true,
                ]);

                // Click-through: property is via property_id already; contacts via deal_contacts.
                DB::table('deal_contacts')->insert([
                    ['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()],
                    ['deal_id' => $deal->id, 'contact_id' => $seller->id, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()],
                ]);

                // Commission splits (deal_user) — the table cc1 found empty. Listing side always
                // the listing agent at 100%; selling side is the (possibly same) selling agent —
                // when double-ended, one agent holds BOTH sides' pivot rows (side is part of the
                // unique key, so this is a normal, legal double-ended deal, not a duplicate).
                DB::table('deal_user')->insert([
                    ['deal_id' => $deal->id, 'user_id' => $listingAgent->id, 'side' => 'listing', 'agent_split_percent' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['deal_id' => $deal->id, 'user_id' => $sellingAgent->id, 'side' => 'selling', 'agent_split_percent' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                // Drive the REAL pipeline engine — same code PipelineController uses.
                $deal = $service->createPipeline($deal, (int) $templateId, ['from_date' => $dealDate->toDateString()]);

                foreach ($stepsToComplete as $stepName) {
                    $this->completeNamedStep($service, $deal, $stepName, $dealDate, $ageDays, count($stepsToComplete));
                }

                $created[] = $deal->id;
            }

            return ['inserted' => count($created), 'deal_ids' => $created];
        });
    }

    /**
     * Complete the step by name (in whichever position it currently holds — activateStep
     * already opened it via the dependency chain from the prior completion), then backdate
     * its timestamps so "time in stage" reads as genuine progress, not everything stamped
     * at seed time. The service call itself is what advances accepted_status/granted_at/
     * registration_date — the backdate below is cosmetic (display only), never a substitute
     * for it.
     */
    private function completeNamedStep(Dr1PipelineService $service, Deal $deal, string $stepName, $dealDate, int $ageDays, int $totalSteps): void
    {
        $step = DealStepInstance::where('dr1_deal_id', $deal->id)
            ->where('name', $stepName)
            ->whereNull('deleted_at')
            ->first();
        if (! $step || $step->status === 'completed') {
            return; // named step not on this template, or already done — never fail the batch on one deal.
        }
        // A step must be 'active' (or already 'not_started' but on_creation) before completeStep()
        // will accept it in real usage; the pipeline's own trigger chain should have activated it
        // by the time we reach it in template order, but guard anyway rather than let one gap
        // abort the whole deal.
        if ($step->status === 'not_started') {
            $service->activateStep($step, $dealDate->toDateString());
            $step->refresh();
        }

        $service->completeStep($step, null, ['outcome' => 'positive']);

        // Cosmetic backdate: spread completions evenly across the deal's elapsed age so the
        // timeline looks like real progress, not a burst seeded in one second.
        static $callIndex = 0;
        $callIndex++;
        $completedAt = $dealDate->copy()->addDays((int) round(($ageDays / max(1, $totalSteps)) * $callIndex));
        if ($completedAt->isFuture()) {
            $completedAt = now();
        }
        DB::table('deal_step_instances')->where('id', $step->id)->update([
            'completed_at' => $completedAt,
            'activated_at' => $completedAt->copy()->subDays(1),
        ]);
    }

    /**
     * Soft-delete (never hard-delete) this seeder's own prior batch, identified by the
     * file_no marker — never touches DemoDealsSeeder's comps (file_no 'DEMO/2024/N') or
     * any other deal.
     */
    private function archivePriorBatch(int $agencyId): void
    {
        $dealIds = DB::table('deals')
            ->where('agency_id', $agencyId)
            ->where('file_no', 'like', self::FILE_NO_PREFIX . '%')
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($dealIds->isEmpty()) {
            return;
        }

        $now = now();
        // Verified schema before writing this (2026-09-02): deal_step_instances, deal_logs
        // and deals all carry deleted_at — soft-deleted below, no exceptions. deal_user,
        // deal_contacts and deal_step_instance_dependencies genuinely have NO deleted_at
        // column on this schema — they are pure pivot/link rows with no meaning independent
        // of their (soft-deleted, still-present) parent deal/step, so removing them here is
        // cleanup of a link, not deletion of a record; the record itself (the deal) is
        // preserved below.
        DB::table('deal_step_instance_dependencies')
            ->whereIn('deal_step_instance_id', DB::table('deal_step_instances')->whereIn('dr1_deal_id', $dealIds)->pluck('id'))
            ->delete();
        DB::table('deal_step_instances')->whereIn('dr1_deal_id', $dealIds)->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
        DB::table('deal_user')->whereIn('deal_id', $dealIds)->delete();
        DB::table('deal_contacts')->whereIn('deal_id', $dealIds)->delete();
        DB::table('deal_logs')->whereIn('deal_id', $dealIds)->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
        DB::table('deals')->whereIn('id', $dealIds)->update(['deleted_at' => $now, 'updated_at' => $now]);
    }
}
