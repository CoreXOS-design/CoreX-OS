<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\DocumentFiling;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo filing-register data — a PPRA-style record of documents filed per
 * deal. In production a DocumentFiling row is created MANUALLY by a clerk/
 * admin (DocumentFilingController::store()) — there is no domain-event
 * listener or automatic creation path (confirmed: no Observer, no listener
 * references DocumentFiling, no mention in the domain-events catalogue). So
 * "realistic demo data" means one filing row per a subset of the agency's
 * own registered demo deals, exactly what a clerk would have filed for each.
 *
 * IDEMPOTENT BY CONSTRUCTION: firstOrCreate keyed on
 * (agency_id, file_reference) — file_reference is deterministically derived
 * from the deal's own deal_no, so a re-run against the same deals never
 * duplicates.
 */
class DemoFilingRegisterSeeder
{
    private const DOCUMENT_TYPES = ['OA', 'OA', 'OA', 'EA', 'Other']; // mostly Offer Agreements, some Estate Agency / Other

    /** @return array{inserted:int, note?:string} */
    public function run(int $agencyId = 1): array
    {
        $deals = DB::table('deals')
            ->where('agency_id', $agencyId)
            ->where('is_demo', true)
            ->where('commission_status', 'registered')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'deal_no', 'branch_id', 'property_address', 'seller_name', 'registration_date']);

        if ($deals->isEmpty()) {
            return ['inserted' => 0, 'note' => 'Skipped — no registered demo deals present (run DemoDealsSeeder first).'];
        }

        $agentIds = DB::table('users')->where('agency_id', $agencyId)
            ->whereIn('role', ['agent', 'admin', 'branch_manager'])
            ->orderBy('id')->pluck('id')->all();
        $branchIds = DB::table('branches')->where('agency_id', $agencyId)->pluck('id')->all();
        if (empty($agentIds) || empty($branchIds)) {
            return ['inserted' => 0, 'note' => 'Skipped — agency has no agents/branches.'];
        }

        $inserted = 0;
        $sequenceCursor = DB::table('document_filing_register')
            ->where('agency_id', $agencyId)
            ->where('sequence_number', 'like', 'DEMO-%')
            ->count();

        foreach ($deals as $idx => $deal) {
            $fileReference = 'FR/DEMO/' . $deal->deal_no;
            $branchId = $deal->branch_id ?: $branchIds[$idx % count($branchIds)];
            $agentId = $agentIds[$idx % count($agentIds)];
            $documentType = self::DOCUMENT_TYPES[$idx % count(self::DOCUMENT_TYPES)];

            $regDate = $deal->registration_date
                ? \Illuminate\Support\Carbon::parse($deal->registration_date)
                : now()->subMonths(3);

            $candidateSequence = 'DEMO-' . str_pad((string) ($sequenceCursor + 1), 4, '0', STR_PAD_LEFT);

            $filing = DocumentFiling::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'file_reference' => $fileReference],
                [
                    'branch_id'         => $branchId,
                    'agent_id'          => $agentId,
                    'document_type'     => $documentType,
                    'sequence_number'   => $candidateSequence,
                    'property_address'  => $deal->property_address ?? 'Unknown address',
                    'seller_name'       => $deal->seller_name,
                    'expiry_date'       => $regDate->copy()->addYears(5)->toDateString(),
                    'notes'             => 'Filed on registration — demo record.',
                    'captured_by'       => $agentId,
                ]
            );

            if ($filing->wasRecentlyCreated) {
                $inserted++;
                $sequenceCursor++;
            }
        }

        $note = "Filing register: +{$inserted} entries (one per registered demo deal).";

        return ['inserted' => $inserted, 'note' => $note];
    }
}
