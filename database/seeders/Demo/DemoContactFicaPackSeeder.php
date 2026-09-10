<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Document;
use App\Models\FicaSubmission;
use App\Services\Security\MediaCipher;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Expanded mandate (2026-09-02, "does this read like a live system?") —
 * FICA status already varied (33/290 contacts had a submission, 16
 * approved) but was HOLLOW: 0 rows in fica_documents anywhere, so even the
 * 16 "approved" contacts had no actual ID/proof-of-address file, and 15 of
 * the 16 had no completion-report PDF either. 256/290 contacts (88%) had
 * no FICA activity at all.
 *
 * Two jobs:
 *   1. TOP UP the 16 existing approved submissions with real fica_documents
 *      (id_copy + proof_of_address, accepted) and a completion-report PDF
 *      (a lightweight dompdf placeholder — NOT the production
 *      FicaCompletionReportService, which shells out to a Puppeteer/Node
 *      renderer per call and is too slow/fragile to run ~50 times in a
 *      seeder; the "download PDF" button still works correctly since it
 *      just serves whatever file sits at pdf_path) — then replicate
 *      FicaController::fileDocumentsToContact()'s exact Drive-filing logic
 *      (same MediaCipher call, same Document+document_contacts shape) so
 *      the contact's Drive tab shows the filed copies too, exactly as a
 *      real approval would leave them.
 *   2. WIDEN coverage across contacts who have never touched FICA,
 *      prioritising Buyer/Seller-role contacts (the ones who'd actually
 *      need to transact), with a REALISTIC STATUS MIX — not everyone
 *      approved. Draft/submitted get no or partial documents; under_review/
 *      agent_approved get uploaded-but-not-yet-accepted documents;
 *      approved gets the full accepted pack + filed Drive copies + report;
 *      a few rejected with a rejection reason, for the mix Johan asked for.
 *
 * IDEMPOTENT BY CONSTRUCTION — top-up only touches submissions/documents
 * currently missing; coverage widening only ever creates a submission for
 * a contact with zero existing fica_submissions rows.
 */
class DemoContactFicaPackSeeder
{
    /**
     * TOTAL contacts with a fica_submissions row this seeder aims for (was
     * 33 before this seeder). 213 — a real 73% coverage mix (40 draft, 59
     * submitted, 34 under_review, 20 agent_approved, 50 approved, 10
     * rejected), leaving the remaining 77 contacts with zero FICA activity
     * (reads as brand-new prospects) — not the earlier bug where an
     * uncapped "+70 every run" widened coverage past this on repeated runs
     * before this constant existed.
     */
    private const TOTAL_COVERAGE_TARGET = 213;

    /** @return array{documents_added:int, reports_generated:int, new_submissions:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        $documentsAdded = 0;
        $reportsGenerated = 0;

        // ── 1. Top up existing approved submissions ──
        $approved = FicaSubmission::where('agency_id', $agencyId)->where('status', 'approved')->get();
        foreach ($approved as $submission) {
            $documentsAdded += $this->ensureDocuments($submission, ['id_copy', 'proof_of_address'], 'accepted');
            if (empty($submission->pdf_path) || !Storage::disk('local')->exists($submission->pdf_path)) {
                $path = $this->generateCompletionReport($submission);
                if ($path) {
                    $submission->update(['pdf_path' => $path]);
                    $reportsGenerated++;
                }
            }
            $this->fileDocumentsToContactDrive($submission);
        }

        // ── 2. Widen coverage ──
        $newSubmissions = $this->widenCoverage($agencyId, $documentsAdded, $reportsGenerated);

        $note = "FICA pack: +{$documentsAdded} documents, +{$reportsGenerated} completion reports, +{$newSubmissions['count']} new submissions across a realistic status mix.";

        return [
            'documents_added'  => $documentsAdded,
            'reports_generated' => $reportsGenerated,
            'new_submissions'  => $newSubmissions['count'],
            'note'             => $note,
        ];
    }

    /** @return array{count:int} */
    private function widenCoverage(int $agencyId, int &$documentsAdded, int &$reportsGenerated): array
    {
        $alreadyCovered = FicaSubmission::where('agency_id', $agencyId)->pluck('contact_id')->filter()->all();

        $need = max(0, self::TOTAL_COVERAGE_TARGET - count($alreadyCovered));
        if ($need === 0) {
            return ['count' => 0];
        }

        // Prioritise buyer/seller-role contacts (they're the ones who'd need FICA
        // to actually transact), oldest-id-first for determinism.
        $buyerSellerIds = DB::table('contact_property')
            ->whereIn('role', ['buyer', 'seller'])
            ->whereIn('contact_id', DB::table('contacts')->where('agency_id', $agencyId)->pluck('id'))
            ->pluck('contact_id')->unique()->values();

        $candidates = DB::table('contacts')
            ->whereIn('id', $buyerSellerIds)
            ->whereNotIn('id', $alreadyCovered)
            ->orderBy('id')
            ->limit($need)
            ->get(['id', 'first_name', 'last_name']);

        if ($candidates->isEmpty()) {
            return ['count' => 0];
        }

        $userIds = DB::table('users')->where('agency_id', $agencyId)->whereIn('role', ['agent', 'admin', 'branch_manager'])->orderBy('id')->pluck('id')->all();
        if (empty($userIds)) {
            return ['count' => 0];
        }

        // Realistic distribution across a real compliance pipeline in progress.
        $plan = array_merge(
            array_fill(0, 12, 'draft'),
            array_fill(0, 18, 'submitted'),
            array_fill(0, 10, 'under_review'),
            array_fill(0, 8,  'agent_approved'),
            array_fill(0, 17, 'approved'),
            array_fill(0, 5,  'rejected'),
        );
        $planLen = count($plan);

        $created = 0;
        foreach ($candidates as $idx => $contact) {
            $status = $plan[$idx % $planLen];
            $userId = $userIds[$idx % count($userIds)];
            $submittedAt = now()->subDays(3 + ($idx % 90));

            $attrs = [
                'contact_id'   => $contact->id,
                'agency_id'    => $agencyId,
                'requested_by' => $userId,
                'entity_type'  => 'natural',
                'intake_type'  => 'online',
                'status'       => $status,
                'created_at'   => $submittedAt,
                'updated_at'   => $submittedAt,
            ];

            if (in_array($status, ['submitted', 'under_review', 'agent_approved', 'approved', 'rejected'], true)) {
                $attrs['form_data'] = ['personal' => ['full_name' => trim($contact->first_name . ' ' . $contact->last_name)]];
            }

            if ($status === 'agent_approved' || $status === 'approved') {
                $attrs['agent_verified_by'] = $userId;
                $attrs['agent_verified_at'] = $submittedAt->copy()->addDays(1);
            }

            if ($status === 'approved') {
                $attrs['verified_by'] = $userIds[($idx + 1) % count($userIds)];
                $attrs['verified_at'] = $submittedAt->copy()->addDays(3);
                $attrs['risk_rating'] = [1, 1, 2, 2, 3][$idx % 5];
                $attrs['fica_expires_at'] = now()->addMonths(24 - ($idx % 6))->toDateString();
                $attrs['co_verified_by'] = $userIds[($idx + 1) % count($userIds)];
                $attrs['co_verified_at'] = $submittedAt->copy()->addDays(3);
            }

            if ($status === 'rejected') {
                $attrs['reviewer_notes'] = 'ID document image was too low-resolution to verify — requested a clearer scan.';
            }

            $submission = FicaSubmission::create($attrs);
            $created++;

            match ($status) {
                'draft'      => null, // nothing uploaded yet — genuinely brand new
                'submitted'  => $documentsAdded += $this->ensureDocuments($submission, ['id_copy'], 'uploaded'),
                'under_review', 'agent_approved' => $documentsAdded += $this->ensureDocuments($submission, ['id_copy', 'proof_of_address'], 'uploaded'),
                'rejected'   => $documentsAdded += $this->ensureDocuments($submission, ['id_copy'], 'rejected', 'Image unreadable — please re-upload a clearer copy.'),
                'approved'   => (function () use ($submission, &$documentsAdded, &$reportsGenerated) {
                    $documentsAdded += $this->ensureDocuments($submission, ['id_copy', 'proof_of_address'], 'accepted');
                    $path = $this->generateCompletionReport($submission);
                    if ($path) {
                        $submission->update(['pdf_path' => $path]);
                        $reportsGenerated++;
                    }
                    $this->fileDocumentsToContactDrive($submission);
                })(),
                default => null,
            };
        }

        return ['count' => $created];
    }

    /** @return int documents newly created */
    private function ensureDocuments(FicaSubmission $submission, array $types, string $status, ?string $rejectionReason = null): int
    {
        $created = 0;
        foreach ($types as $type) {
            $exists = DB::table('fica_documents')
                ->where('fica_submission_id', $submission->id)
                ->where('document_type', $type)
                ->exists();
            if ($exists) {
                continue;
            }

            $contactName = trim(($submission->contact->first_name ?? '') . ' ' . ($submission->contact->last_name ?? '')) ?: 'Client';
            $label = $type === 'id_copy' ? 'Identity Document' : 'Proof of Residence';
            $fileName = Str::slug($contactName) . '-' . $type . '.pdf';
            $bytes = $this->generatePlaceholderPdf($label, $contactName, $type);

            $path = "fica-documents/{$submission->id}/" . Str::uuid() . '.pdf';
            Storage::disk('public')->put($path, $bytes);

            DB::table('fica_documents')->insert([
                'fica_submission_id' => $submission->id,
                'agency_id'          => $submission->agency_id,
                'document_type'      => $type,
                'file_path'          => $path,
                'file_name'          => $fileName,
                'file_size'          => strlen($bytes),
                'mime_type'          => 'application/pdf',
                'status'             => $status,
                'rejection_reason'   => $rejectionReason,
                'uploaded_at'        => $submission->created_at,
                'reviewed_at'        => $status !== 'uploaded' ? now() : null,
                'uploaded_by'        => $submission->requested_by,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Mirrors FicaController::fileDocumentsToContact() exactly (same
     * DocumentType slug map, same MediaCipher call — a no-op copy here
     * since MediaCipher is disabled on this demo box) so the contact's
     * Drive tab shows the filed copies, identically to a real approval.
     */
    private function fileDocumentsToContactDrive(FicaSubmission $submission): void
    {
        $contact = $submission->contact;
        if (!$contact) {
            return;
        }

        $submission->loadMissing('documents');
        if ($submission->documents->isEmpty()) {
            return;
        }

        $typeMap = [
            'fica_form' => 'fica', 'id_copy' => 'ids', 'proof_of_address' => 'por',
            'authority' => 'power_of_attorney', 'bank_statement' => 'bank_statement',
            'tax_clearance' => 'tax_clearance', 'company_registration' => 'company_registration',
            'trust_deed' => 'trust_deed', 'supporting' => 'other', 'other' => 'other',
        ];
        $slugToId = DB::table('document_types')->pluck('id', 'slug')->toArray();
        $cipher = app(MediaCipher::class);
        $localDisk = Storage::disk('local');
        $publicDisk = Storage::disk('public');

        foreach ($submission->documents as $ficaDoc) {
            $alreadyFiled = DB::table('documents')
                ->where('source_type', 'fica')->where('source_id', $submission->id)
                ->where('original_name', $ficaDoc->file_name)
                ->exists();
            if ($alreadyFiled) {
                continue;
            }

            $slug = $typeMap[$ficaDoc->document_type] ?? 'other';
            $docTypeId = $slugToId[$slug] ?? ($slugToId['other'] ?? null);
            if (!$docTypeId || !$publicDisk->exists($ficaDoc->file_path)) {
                continue;
            }

            $ext = pathinfo($ficaDoc->file_name, PATHINFO_EXTENSION) ?: 'pdf';
            $newPath = "contact-documents/{$contact->id}/" . Str::uuid() . ".{$ext}";
            $plainBytes = $publicDisk->get($ficaDoc->file_path);
            $localDisk->put($newPath, $cipher->enabled() ? $cipher->encrypt($plainBytes) : $plainBytes);

            $document = Document::create([
                'agency_id'        => $submission->agency_id,
                'original_name'    => $ficaDoc->file_name,
                'storage_path'     => $newPath,
                'disk'             => 'local',
                'mime_type'        => $ficaDoc->mime_type,
                'size'             => $ficaDoc->file_size,
                'document_type_id' => $docTypeId,
                'source_type'      => 'fica',
                'source_id'        => $submission->id,
                'uploaded_by'      => $submission->requested_by,
            ]);
            $document->contacts()->attach($contact->id);
        }
    }

    private function generateCompletionReport(FicaSubmission $submission): ?string
    {
        $submission->loadMissing(['contact', 'requestedBy', 'agentVerifiedBy', 'coVerifiedBy']);
        $contactName = trim(($submission->contact->first_name ?? '') . ' ' . ($submission->contact->last_name ?? '')) ?: 'Client';

        $html = "<html><body style='font-family: sans-serif; padding: 40px;'>"
            . "<h2 style='color:#0b2a4a;'>CoreX Demo Realty — FICA Completion Report</h2>"
            . "<p style='color:#dc2626; font-weight:bold;'>DEMO DOCUMENT — synthetic placeholder for demo purposes.</p>"
            . "<p><strong>Client:</strong> {$contactName}</p>"
            . '<p><strong>Risk rating:</strong> ' . ($submission->risk_rating ?? '—') . '</p>'
            . '<p><strong>Verified by:</strong> ' . ($submission->coVerifiedBy->name ?? $submission->requestedBy->name ?? 'Compliance Officer') . '</p>'
            . '<p><strong>Approved:</strong> ' . optional($submission->verified_at)->format('d F Y') . '</p>'
            . "<hr><p style='font-size:10px; color:#94a3b8;'>Generated for demo purposes only. Submission #{$submission->id}.</p>"
            . '</body></html>';

        $bytes = Pdf::loadHTML($html)->output();
        $path = "compliance/fica/{$submission->id}/completion-report-" . time() . '.pdf';
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    private function generatePlaceholderPdf(string $label, string $contactName, string $type): string
    {
        $issued = now()->subDays(random_int(10, 300))->format('d F Y');
        $html = "<html><body style='font-family: sans-serif; padding: 40px;'>"
            . "<h2 style='color:#0b2a4a;'>CoreX Demo Realty — {$label}</h2>"
            . "<p style='color:#dc2626; font-weight:bold;'>DEMO DOCUMENT — synthetic placeholder, not a real identity document.</p>"
            . "<p><strong>Name:</strong> {$contactName}</p>"
            . "<p><strong>Document type:</strong> {$label}</p>"
            . "<p><strong>Captured:</strong> {$issued}</p>"
            . "<hr><p style='font-size:10px; color:#94a3b8;'>Generated for demo purposes only. Reference: " . strtoupper($type) . '-' . Str::random(8) . '</p>'
            . '</body></html>';

        return Pdf::loadHTML($html)->output();
    }
}
