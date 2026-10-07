<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §15.10 M5 (Build L1 — foundation) — DATA ONLY, idempotent, DML-only.
 * Gives every lease that already exists the signing state it truthfully has today, so no screen
 * built on `leases.signing_status` starts out wrong for a lease that predates it.
 *
 *   1. signing_flow_id mirrors renewal_draft_flow_id.
 *   2. A DRAFT lease with a flow: prepared, or the envelope's own state once the agent has prepared
 *      signing (flow step_data.signature_template_id). Cancelled / active / expired leases are not
 *      "in flight", so they are left alone (not_sent).
 *   3. source='esign_document' leases with a source_document_id: signed, with the envelope and
 *      document links and signed_at = accepted_at = the envelope's completed_at where it can be found.
 *   4. A paper renewal (documents.source_type='lease', source_id = the lease): signed_on_paper.
 *   5. Everything else stays not_sent (the column default).
 *
 * Only rows still at `not_sent` / with a null link are written, so re-running changes nothing, and a
 * row that has since moved on (a real signing flow) is never overwritten.
 */
return new class extends Migration
{
    /** SignatureTemplate status → leases.signing_status (§15.5). Mirrors the one method L3b adds to Lease. */
    private const ENVELOPE_TO_SIGNING = [
        'pending_agent_approval' => 'awaiting_agent_review',
        'returned_to_candidate' => 'awaiting_agent_review',
        'amendment_review' => 'awaiting_agent_review',
        'amendment_initialing' => 'awaiting_agent_review',
        'amendment_chain_review' => 'awaiting_agent_review',
        'editor_reacceptance' => 'awaiting_agent_review',
        'completed' => 'signed',
        'declined' => 'declined',
        'rejected' => 'declined',
        'cancelled' => 'voided',
        'expired' => 'expired',
        'lapsed' => 'expired',
        're_lapsed' => 'expired',
    ];

    public function up(): void
    {
        // 1 — mirror the renewal draft flow.
        DB::table('leases')
            ->whereNull('signing_flow_id')
            ->whereNotNull('renewal_draft_flow_id')
            ->update(['signing_flow_id' => DB::raw('renewal_draft_flow_id')]);

        // 2 — draft leases that have a flow.
        DB::table('leases')
            ->where('status', 'draft')
            ->where('signing_status', 'not_sent')
            ->whereNotNull('signing_flow_id')
            ->orderBy('id')
            ->chunkById(200, function ($leases) {
                foreach ($leases as $lease) {
                    $flow = DB::table('flows')->where('id', $lease->signing_flow_id)->whereNull('deleted_at')->first();
                    if (! $flow) {
                        continue;
                    }

                    $stepData = json_decode((string) $flow->step_data, true);
                    $envelopeId = is_array($stepData) ? ($stepData['signature_template_id'] ?? null) : null;
                    $envelope = $envelopeId
                        ? DB::table('signature_templates')->where('id', $envelopeId)->whereNull('deleted_at')->first()
                        : null;

                    if (! $envelope) {
                        DB::table('leases')->where('id', $lease->id)->update(['signing_status' => 'prepared']);
                        continue;
                    }

                    $documentId = $envelope->document_id ?: (is_array($stepData) ? ($stepData['document_id'] ?? null) : null);
                    DB::table('leases')->where('id', $lease->id)->update([
                        'signing_status' => self::ENVELOPE_TO_SIGNING[$envelope->status] ?? 'out_for_signing',
                        'signature_template_id' => $envelope->id,
                        'agreement_document_id' => $documentId,
                    ]);
                }
            });

        // 3 — leases made from a signed e-sign document.
        DB::table('leases')
            ->where('source', 'esign_document')
            ->whereNotNull('source_document_id')
            ->where('signing_status', 'not_sent')
            ->orderBy('id')
            ->chunkById(200, function ($leases) {
                foreach ($leases as $lease) {
                    $envelope = DB::table('signature_templates')
                        ->where('document_id', $lease->source_document_id)
                        ->whereNull('deleted_at')
                        ->orderByRaw("status = 'completed' desc")
                        ->orderByDesc('id')
                        ->first();

                    $update = [
                        'signing_status' => 'signed',
                        'agreement_document_id' => $lease->source_document_id,
                    ];
                    if ($envelope) {
                        $update['signature_template_id'] = $envelope->id;
                        if ($envelope->completed_at) {
                            $update['signed_at'] = $envelope->completed_at;
                            $update['accepted_at'] = $envelope->completed_at;
                        }
                    }
                    DB::table('leases')->where('id', $lease->id)->update($update);
                }
            });

        // 4 — paper renewals.
        DB::table('leases')
            ->where('signing_status', 'not_sent')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('documents')
                    ->whereColumn('documents.source_id', 'leases.id')
                    ->where('documents.source_type', 'lease')
                    ->whereNull('documents.deleted_at');
            })
            ->update(['signing_status' => 'signed_on_paper']);
    }

    public function down(): void
    {
        // Data back-fill only — nothing to undo (the columns themselves are dropped by M2's down()).
    }
};
