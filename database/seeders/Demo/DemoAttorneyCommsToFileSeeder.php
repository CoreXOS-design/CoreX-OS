<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationFilingSuspense;
use Illuminate\Support\Facades\DB;

/**
 * Webinar-eve gap fix (2026-09-02) — Johan: "can we put some attorney emails
 * under communication - to file (attorney emails)".
 *
 * This is AT-231 P2b's suspense queue (.ai/specs/at231-inbound-attorney-comms-
 * filing.md) — `communications` (channel=email, direction=inbound) rows each
 * paired with a `communication_filing_suspense` row. The review screen
 * (CommsSuspenseController::index) gates visibility purely on the SUGGESTED/
 * RESOLVED DEAL being Deal::visibleTo($user) — admin has data-scope 'all', so
 * nothing extra is needed on the communication row itself for it to render.
 *
 * Filed against the DR2 pipeline demo batch (DemoDr2PipelineSeeder,
 * file_no LIKE 'DR2-PIPELINE-DEMO/%') — those deals are mid-transaction
 * (Pending/Granted), the honest target for live attorney correspondence;
 * the 900000+ historic comps are already closed and not a realistic target.
 *
 * Fictional SA conveyancing firm names only — invented, no real firm, no HFC.
 *
 * No outbound side effects: no observer/booted() hook on either model —
 * confirmed by reading both model files. Inserting rows directly cannot send
 * mail or call any service.
 *
 * Idempotent: identified by source_ref = self::SOURCE_REF, archived (soft-
 * deleted, suspense rows first then their parent communications) then
 * recreated fresh — dates must stay relative to now().
 */
final class DemoAttorneyCommsToFileSeeder
{
    private const SOURCE_REF = 'demo-attorney-comms-batch';

    private const FIRMS = [
        ['name' => 'Marimba & Cronje Attorneys',    'domain' => 'marimbacronje-conveyancing.invalid'],
        ['name' => 'Ndzimande Naidoo Inc.',         'domain' => 'ndzimandenaidoo-law.invalid'],
        ['name' => 'Van Wyk Fourie Conveyancers',   'domain' => 'vwfconveyancers.invalid'],
        ['name' => 'Radebe & Partners',             'domain' => 'radebepartners-attorneys.invalid'],
    ];

    private const PLAN = [
        // [dealCursor, firmIdx, confidence, status, daysAgo, subjectKind]
        [0, 0, 'high',   'pending',  1, 'otp'],
        [1, 1, 'high',   'pending',  2, 'bond_guarantee'],
        [2, 2, 'medium', 'pending',  4, 'rates_clearance'],
        [3, 3, 'medium', 'pending',  6, 'transfer_duty'],
        [4, 0, 'low',    'pending',  9, 'general_query'],
        [5, 1, 'high',   'verified', 14, 'deeds_lodgement'],
        [6, 2, 'high',   'verified', 20, 'guarantees_issued'],
    ];

    public function run(int $agencyId): array
    {
        $this->archivePriorBatch($agencyId);

        $deals = DB::table('deals')
            ->where('agency_id', $agencyId)
            ->where('file_no', 'like', 'DR2-PIPELINE-DEMO/%')
            ->whereNull('deleted_at')
            ->orderBy('deal_no')
            ->get(['id', 'deal_no', 'property_id']);

        if ($deals->isEmpty()) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} has no DR2 pipeline demo deals to file against. Run DemoDr2PipelineSeeder first."];
        }

        $dealList = $deals->values();
        $adminUserId = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->value('id');

        $inserted = 0;

        foreach (self::PLAN as $i => [$dealCursor, $firmIdx, $confidence, $status, $daysAgo, $kind]) {
            $deal = $dealList[$dealCursor % $dealList->count()];
            $firm = self::FIRMS[$firmIdx % count(self::FIRMS)];
            $property = DB::table('properties')->where('id', $deal->property_id)->first(['address', 'suburb']);
            $address = $property ? trim($property->address . ', ' . $property->suburb, ', ') : "Deal #{$deal->deal_no}";

            [$subject, $body] = $this->emailContent($kind, $deal->deal_no, $address, $firm['name']);
            $occurredAt = now()->subDays($daysAgo)->subHours(random_int(1, 6));
            $fromEmail = 'conveyancing@' . $firm['domain'];

            $communication = Communication::create([
                'agency_id' => $agencyId,
                'channel' => Communication::CHANNEL_EMAIL,
                'direction' => Communication::DIRECTION_INBOUND,
                'external_id' => self::SOURCE_REF . '/' . $deal->deal_no . '/' . $i,
                'thread_key' => self::SOURCE_REF . '-thread-' . $i,
                'from_identifier' => $fromEmail,
                'participant_identifiers' => [$fromEmail, 'transfers@demo-inert.invalid'],
                'occurred_at' => $occurredAt,
                'captured_at' => $occurredAt->copy()->addMinutes(random_int(1, 20)),
                'subject' => $subject,
                'body_text' => $body,
                'body_preview' => \Illuminate\Support\Str::limit($body, 160),
                'has_attachments' => false,
                'send_status' => Communication::SEND_STATUS_SENT,
                'source_ref' => self::SOURCE_REF,
            ]);
            $inserted++;

            $isVerified = $status === 'verified';
            CommunicationFilingSuspense::create([
                'agency_id' => $agencyId,
                'communication_id' => $communication->id,
                'channel' => 'email',
                'suggested_deal_id' => $deal->id,
                'confidence' => $confidence,
                'status' => $isVerified ? CommunicationFilingSuspense::STATUS_VERIFIED : CommunicationFilingSuspense::STATUS_PENDING,
                'resolved_deal_id' => $isVerified ? $deal->id : null,
                'resolved_by_user_id' => $isVerified ? $adminUserId : null,
                'resolved_at' => $isVerified ? $occurredAt->copy()->addDay() : null,
                'matched_signal_type' => $confidence === 'high' ? 'deal_no' : ($confidence === 'medium' ? 'property_address' : null),
                'matched_signal_value' => $confidence === 'high' ? (string) $deal->deal_no : ($confidence === 'medium' ? $address : null),
            ]);
        }

        return ['inserted' => $inserted];
    }

    private function emailContent(string $kind, int $dealNo, string $address, string $firm): array
    {
        return match ($kind) {
            'otp' => [
                "Signed Offer to Purchase — {$address} (Ref {$dealNo})",
                "Good day,\n\nPlease find attached the signed Offer to Purchase for the above property. Kindly confirm receipt and advise on next steps for the guarantee process.\n\nKind regards,\n{$firm}",
            ],
            'bond_guarantee' => [
                "Bond Guarantee Request — {$address}",
                "Good day,\n\nWe act on behalf of the purchaser's bondholder. Please provide the settlement figures so we may proceed with the guarantee request.\n\nRegards,\n{$firm} — Conveyancing Department",
            ],
            'rates_clearance' => [
                "Rates Clearance Certificate application — {$address}",
                "Dear Sir/Madam,\n\nWe have submitted the rates clearance application to the municipality for the above property. We will revert once the certificate has been issued.\n\nRegards,\n{$firm}",
            ],
            'transfer_duty' => [
                "Transfer Duty Receipt — {$address}",
                "Good day,\n\nThe transfer duty has been paid to SARS and the receipt is attached for your records. We are proceeding to lodgement.\n\nRegards,\n{$firm}",
            ],
            'deeds_lodgement' => [
                "Lodged at Deeds Office — {$address}",
                "Good day,\n\nPlease be advised that the transfer documents have been lodged at the Deeds Office today. We anticipate registration within 7-10 working days.\n\nRegards,\n{$firm}",
            ],
            'guarantees_issued' => [
                "Guarantees Issued — {$address}",
                "Good day,\n\nThe bank guarantees have been issued and delivered to the bondholder's attorneys. Awaiting their acceptance before we can proceed to lodgement.\n\nRegards,\n{$firm}",
            ],
            default => [
                "Correspondence regarding {$address}",
                "Good day,\n\nKindly see our query below regarding the above transaction.\n\nRegards,\n{$firm}",
            ],
        };
    }

    private function archivePriorBatch(int $agencyId): void
    {
        DB::transaction(function () use ($agencyId) {
            $now = now();
            $commIds = DB::table('communications')
                ->where('agency_id', $agencyId)
                ->where('source_ref', self::SOURCE_REF)
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($commIds->isEmpty()) {
                return;
            }

            DB::table('communication_filing_suspense')
                ->whereIn('communication_id', $commIds)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now]);

            // `communications` has a UNIQUE (agency_id, external_id) index that is NOT
            // partial — a soft-deleted row still occupies its external_id, so the next
            // run's insert of the same external_id throws a duplicate-key error. Free
            // the key by mutating the archived row's external_id (it's inert history
            // now, nothing reads it) before soft-deleting.
            foreach ($commIds as $commId) {
                DB::table('communications')
                    ->where('id', $commId)
                    ->update([
                        'external_id' => self::SOURCE_REF . '-archived-' . $commId . '-' . $now->timestamp,
                        'deleted_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        });
    }
}
