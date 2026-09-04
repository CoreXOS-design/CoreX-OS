<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Services\Compliance\FicaDocumentStorage;
use Illuminate\Support\Str;

/**
 * Attaches ACTUAL FicaDocument files to the existing fica_submissions rows
 * (seeded earlier by stage8_fica() — 33 submissions across a realistic
 * status spread) — every one of them had zero fica_documents. A FICA
 * verification "in progress" or "approved" with no ID copy / proof of
 * address on file reads as broken, not as a working compliance process.
 *
 * Written through the REAL storage seam (FicaDocumentStorage::putBytes(),
 * AT-173) so files are genuinely encrypted-at-rest on the private disk
 * exactly like a real upload, and FicaController::viewDocument()'s
 * decrypt-and-stream path genuinely works — not a DB row pointing at
 * nothing.
 *
 * DELIBERATE MIX, matching each submission's real status (not uniform):
 *  - draft (4): 0 documents — genuinely just started.
 *  - submitted (5): 1 document (ID copy only) — proof of address not yet
 *    uploaded, a common real-world gap; shows "in progress", not complete.
 *  - under_review / agent_approved (8): 2 documents (ID copy + proof of
 *    address), status='uploaded' — fully submitted, awaiting/mid review.
 *  - approved (16): 2 documents, status='accepted' with reviewed_at set —
 *    a genuinely completed verification.
 *
 * ALL FICTIONAL placeholder PDF bodies — no real ID numbers or addresses.
 *
 * IDEMPOTENT: skips any submission that already has ≥1 fica_documents row.
 */
final class DemoFicaDocumentsSeeder
{
    public function run(int $agencyId): array
    {
        $admin = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'admin')->first();
        if (!$admin) {
            return ['created' => 0, 'skipped' => 0, 'notes' => ['FAILED: no admin user found for agency ' . $agencyId]];
        }

        $submissions = FicaSubmission::withoutGlobalScopes()->where('agency_id', $agencyId)->get();
        if ($submissions->isEmpty()) {
            return ['created' => 0, 'skipped' => 0, 'notes' => ['SKIPPED: no fica_submissions found for agency ' . $agencyId]];
        }

        $storage = app(FicaDocumentStorage::class);

        $created = 0;
        $skipped = 0;
        $notes = [];

        foreach ($submissions as $submission) {
            $already = FicaDocument::withoutGlobalScopes()->where('fica_submission_id', $submission->id)->exists();
            if ($already) {
                $skipped++;
                $notes[] = "SKIPPED (already has documents): submission #{$submission->id} ({$submission->status})";
                continue;
            }

            $types = match ($submission->status) {
                'draft' => [],
                'submitted' => ['id_copy'],
                'under_review', 'agent_approved' => ['id_copy', 'proof_of_address'],
                'approved' => ['id_copy', 'proof_of_address'],
                default => ['id_copy'],
            };

            if (empty($types)) {
                $notes[] = "SKIPPED (draft — no documents expected): submission #{$submission->id}";
                continue;
            }

            $docStatus = $submission->status === 'approved' ? 'accepted' : 'uploaded';
            $uploadedAt = $submission->created_at ?? now();

            foreach ($types as $type) {
                $bytes = $this->placeholderPdf($type, $submission->id);
                $dir = "fica/{$submission->id}";
                $path = $storage->putBytes($dir . '/' . Str::random(40) . '.pdf', $bytes);

                FicaDocument::withoutGlobalScopes()->create([
                    'agency_id' => $agencyId,
                    'fica_submission_id' => $submission->id,
                    'document_type' => $type,
                    'file_path' => $path,
                    'file_name' => '[DEMO] ' . ucwords(str_replace('_', ' ', $type)) . '.pdf',
                    'file_size' => strlen($bytes),
                    'mime_type' => 'application/pdf',
                    'status' => $docStatus,
                    'uploaded_at' => $uploadedAt,
                    'reviewed_at' => $docStatus === 'accepted' ? $uploadedAt->copy()->addDays(1) : null,
                    'uploaded_by' => $admin->id,
                ]);
            }

            $created++;
            $notes[] = 'CREATED ' . count($types) . " document(s): submission #{$submission->id} ({$submission->status})";
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }

    private function placeholderPdf(string $docType, int $submissionId): string
    {
        $label = ucwords(str_replace('_', ' ', $docType));
        $text = "[DEMO] {$label} - submission #{$submissionId} - Fictional document, seeded for demo purposes.";
        $text = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);

        return "%PDF-1.4\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>endobj\n"
            . "4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            . "5 0 obj<</Length " . (strlen($text) + 60) . ">>stream\n"
            . "BT /F1 14 Tf 50 700 Td ({$text}) Tj ET\n"
            . "endstream\nendobj\n"
            . "xref\n0 6\n0000000000 65535 f \n"
            . "trailer<</Size 6/Root 1 0 R>>\n"
            . "startxref\n0\n%%EOF";
    }
}
