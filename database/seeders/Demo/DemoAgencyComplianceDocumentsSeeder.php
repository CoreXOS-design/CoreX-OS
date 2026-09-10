<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds ACTUAL uploaded compliance documents (Compliance -> Agency Documents,
 * /compliance/agency-documents and the my-portal viewer) — the document
 * TYPES were configured earlier tonight (AgencyDocumentTypeConfigSeeder) but
 * zero AgencyComplianceProvision rows existed, so every card showed
 * "Required — not available". An agency with configured-but-empty compliance
 * cards reads as one that has not started, not one that has been operating.
 *
 * Writes REAL small PDF files to the SAME private 'local' disk path
 * (agency-compliance/) the real upload endpoint uses
 * (AgencyComplianceSettingsController::store()), so
 * AgencyDocumentsViewerController::download() genuinely serves them —
 * not just DB rows pointing at nothing.
 *
 * DELIBERATE MIX (not uniformly green) — demonstrates the feature doing its
 * job rather than hiding it:
 *  - FFC Certificate (company-wide): expiring in ~18 days (amber)
 *  - Bank Confirmation Letter (company-wide): EXPIRED a few days ago (red)
 *  - Bank Confirmation Letter (Margate branch override): valid, current
 *    (demonstrates the branch-override-beats-company resolution)
 *  - BEE Certificate (company-wide): valid, comfortably in date (teal)
 *  - CIPC Registration (company-wide): no expiry (teal, "Active, no expiry")
 *  - VAT Certificate (company-wide): no expiry (teal)
 *
 * ALL FICTIONAL — the PDF bodies are placeholder text, no real registration
 * data, no HFC branding.
 *
 * IDEMPOTENT: skips a (document_type_config_id, branch_id) pair that already
 * has an active provision.
 */
final class DemoAgencyComplianceDocumentsSeeder
{
    public function run(int $agencyId): array
    {
        $admin = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'admin')->first();
        if (!$admin) {
            return ['created' => 0, 'skipped' => 0, 'notes' => ['FAILED: no admin user found for agency ' . $agencyId]];
        }

        $configs = AgencyDocumentTypeConfig::withoutGlobalScopes()->where('agency_id', $agencyId)->get()->keyBy('slug');
        $margate = Branch::withoutGlobalScopes()->where('agency_id', $agencyId)->where('name', 'like', 'Margate%')->first();

        $plan = [
            ['slug' => 'ffc_certificate', 'branch_id' => null, 'from' => now()->subMonths(10), 'until' => now()->addDays(18), 'ref' => 'FFC-2026-00147'],
            ['slug' => 'bank_confirmation', 'branch_id' => null, 'from' => now()->subDays(30), 'until' => now()->subDays(4), 'ref' => null],
            ['slug' => 'bank_confirmation', 'branch_id' => $margate?->id, 'from' => now()->subDays(6), 'until' => now()->addDays(8), 'ref' => null],
            ['slug' => 'bee_certificate', 'branch_id' => null, 'from' => now()->subMonths(2), 'until' => now()->addMonths(9), 'ref' => 'BEE-L4-2026-0093'],
            ['slug' => 'cipc_registration', 'branch_id' => null, 'from' => now()->subYears(3), 'until' => null, 'ref' => '2019/000456/07'],
            ['slug' => 'vat_certificate', 'branch_id' => null, 'from' => now()->subYears(3), 'until' => null, 'ref' => 'VAT-4650000001'],
        ];

        $created = 0;
        $skipped = 0;
        $notes = [];

        foreach ($plan as $row) {
            $config = $configs->get($row['slug']);
            if (!$config) {
                $notes[] = "SKIPPED (no such document type config): {$row['slug']}";
                continue;
            }

            $exists = AgencyComplianceProvision::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('document_type_config_id', $config->id)
                ->where('status', 'active')
                ->where(function ($q) use ($row) {
                    $row['branch_id'] ? $q->where('branch_id', $row['branch_id']) : $q->whereNull('branch_id');
                })
                ->exists();

            if ($exists) {
                $skipped++;
                $notes[] = 'SKIPPED (already on file): ' . $config->name . ($row['branch_id'] ? ' (branch override)' : '');
                continue;
            }

            $path = $this->writePlaceholderPdf($agencyId, $config->name, $row['ref']);

            AgencyComplianceProvision::withoutGlobalScopes()->create([
                'agency_id' => $agencyId,
                'document_type_config_id' => $config->id,
                'branch_id' => $row['branch_id'],
                'provision_type' => '',
                'policy_reference' => $row['ref'],
                'effective_from' => $row['from']->toDateString(),
                'effective_until' => $row['until']?->toDateString(),
                'notes' => '[DEMO] Fictional compliance document — seeded for demo purposes.',
                'status' => 'active',
                'created_by' => $admin->id,
                'document_path' => $path,
                'document_original_name' => $config->name . '.pdf',
                'created_at' => $row['from'],
                'updated_at' => $row['from'],
            ]);

            $created++;
            $notes[] = 'CREATED: ' . $config->name . ($row['branch_id'] ? ' (branch override)' : ' (company-wide)');
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }

    /** Write a tiny, genuinely-valid placeholder PDF to the private 'local' disk. */
    private function writePlaceholderPdf(int $agencyId, string $title, ?string $ref): string
    {
        $text = '[DEMO] ' . $title . ($ref ? ' - Ref: ' . $ref : '') . ' - Fictional document, seeded for demo purposes.';
        $text = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);

        $pdf = "%PDF-1.4\n"
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

        $filename = 'agency-compliance/' . $agencyId . '/demo-' . \Illuminate\Support\Str::random(20) . '.pdf';
        Storage::disk('local')->put($filename, $pdf);

        return $filename;
    }
}
