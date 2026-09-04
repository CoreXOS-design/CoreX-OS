<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Deal;
use App\Models\User;
use App\Services\DealV2\DealDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 2026-09-02 webinar prep, expanded mandate — "documents attached across the
 * system" was almost entirely empty: 6/363 properties, 2/290 contacts,
 * 1/125 deals had any document at all. Johan's own words: "properties
 * without documents attached means buyers pipeline suffers."
 *
 * Reuses REAL, already-generated PDF bytes (the genuine signed e-sign
 * documents from tonight's stage9b run, and Johan's own uploaded FICA
 * bundle) — physically copied per new row via Storage::copy(), so every
 * `documents` row this seeder creates has a real, openable file behind it.
 * Never points a row at a path that doesn't exist. Not aiming for 100%
 * uniform coverage (that would look mechanical) — a realistic spread:
 * most stock properties get a mandate, a smaller subset also get a
 * disclosure/marketing-permission; a good spread of contacts get a FICA
 * document; every deal gets an OTP-category document.
 *
 * Idempotent: skips a (record, document_type) pair that already has a row
 * — safe to re-run, never duplicates. is_demo-flagged properties and real
 * seeded contacts/deals only; never touches Johan's own hand-uploaded
 * documents (ids 31/32) or anything HFC.
 */
class DemoDocumentCoverageSeeder
{
    private const SOURCES = [
        'mandate'              => 'docuperfect/signed-documents/16/client_signed.pdf',
        'disclosure'           => 'docuperfect/signed-documents/17/client_signed.pdf',
        'marketing_permission' => 'docuperfect/signed-documents/18/client_signed.pdf',
        'fica'                 => 'properties/2/files/jBzeiPxeMo6iMn4ga8oZt8oS3EiEylqNHwxDPHvC.pdf',
        'ids'                  => 'properties/2/files/jBzeiPxeMo6iMn4ga8oZt8oS3EiEylqNHwxDPHvC.pdf',
        'por'                  => 'properties/2/files/jBzeiPxeMo6iMn4ga8oZt8oS3EiEylqNHwxDPHvC.pdf',
        'otp'                  => 'docuperfect/signed-documents/34/client_signed.pdf',
        'sale_agreement'       => 'docuperfect/signed-documents/34/client_signed.pdf',
    ];

    public function run(int $agencyId = 1): array
    {
        $typeIds = DB::table('document_types')
            ->whereIn('slug', array_keys(self::SOURCES))
            ->pluck('id', 'slug');

        foreach (self::SOURCES as $slug => $path) {
            if (! Storage::disk('local')->exists($path)) {
                return ['error' => "Source PDF missing: {$path}"];
            }
        }

        $uploaderId = DB::table('users')->where('agency_id', $agencyId)
            ->where('role', 'admin')->value('id')
            ?? DB::table('users')->where('agency_id', $agencyId)->value('id');

        $totals = ['properties' => 0, 'contacts' => 0, 'deals' => 0];

        // ── Properties: mandate (most), + disclosure/marketing_permission (subset) ──
        $properties = DB::table('properties')->where('agency_id', $agencyId)
            ->where('is_demo', true)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->all();

        foreach ($properties as $i => $propertyId) {
            $plan = ['mandate'];
            if ($i % 2 === 0) $plan[] = 'disclosure';
            if ($i % 3 === 0) $plan[] = 'marketing_permission';

            foreach ($plan as $slug) {
                if (! isset($typeIds[$slug])) continue;
                $already = DB::table('document_properties')
                    ->join('documents', 'documents.id', '=', 'document_properties.document_id')
                    ->where('document_properties.property_id', $propertyId)
                    ->where('documents.document_type_id', $typeIds[$slug])
                    ->exists();
                if ($already) continue;

                $docId = $this->attachDocument($agencyId, $uploaderId, $slug, $typeIds[$slug], "property-{$propertyId}");
                DB::table('document_properties')->insert([
                    'document_id' => $docId,
                    'property_id' => $propertyId,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $totals['properties']++;
            }
        }

        // ── Contacts: a spread of sellers/buyers get a FICA-category document ──
        $contacts = DB::table('contacts')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        $ficaCycle = ['fica', 'ids', 'por'];

        foreach ($contacts as $i => $contactId) {
            // Half the contact book gets one FICA-category doc — a realistic
            // spread, not every prospecting-stage contact needs FICA on file.
            if ($i % 2 !== 0) continue;

            $slug = $ficaCycle[$i % count($ficaCycle)];
            if (! isset($typeIds[$slug])) continue;

            $already = DB::table('document_contacts')
                ->join('documents', 'documents.id', '=', 'document_contacts.document_id')
                ->where('document_contacts.contact_id', $contactId)
                ->where('documents.document_type_id', $typeIds[$slug])
                ->exists();
            if ($already) continue;

            $docId = $this->attachDocument($agencyId, $uploaderId, $slug, $typeIds[$slug], "contact-{$contactId}");
            DB::table('document_contacts')->insert([
                'document_id' => $docId,
                'contact_id'  => $contactId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $totals['contacts']++;
        }

        // ── Deals: every deal gets an OTP-category document, half also a sale
        // agreement — filed via the REAL DealDocumentService (twin-bridge:
        // also attaches to the deal's property + the property's contacts,
        // same as a real agent's upload). ──
        $dealDocService = app(DealDocumentService::class);
        $uploader = $uploaderId ? User::find($uploaderId) : null;
        $deals = Deal::withoutGlobalScopes()->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->orderBy('id')->get();

        foreach ($deals as $i => $deal) {
            $plan = ['otp'];
            if ($i % 2 === 0) $plan[] = 'sale_agreement';

            foreach ($plan as $slug) {
                if (! isset($typeIds[$slug]) || ! $uploader) continue;
                $already = DB::table('documents')
                    ->where('source_type', 'deal')->where('source_id', $deal->id)
                    ->where('document_type_id', $typeIds[$slug])
                    ->exists();
                if ($already) continue;

                $sourcePath = self::SOURCES[$slug];
                $destPath = 'deal-documents/' . $agencyId . '/' . $deal->id . '/' . Str::random(8) . '-' . $slug . '.pdf';
                Storage::disk('local')->copy($sourcePath, $destPath);

                $label = ucwords(str_replace('_', ' ', $slug));
                $dealDocService->fileDealDocumentFromDeal($deal, [
                    'original_name'    => "{$label} — Deal {$deal->deal_no}.pdf",
                    'storage_path'     => $destPath,
                    'disk'             => 'local',
                    'mime_type'        => 'application/pdf',
                    'size'             => Storage::disk('local')->size($destPath),
                    'document_type_id' => $typeIds[$slug],
                    'source_type'      => 'deal',
                ], $uploader);
                $totals['deals']++;
            }
        }

        return $totals;
    }

    private function attachDocument(int $agencyId, ?int $uploaderId, string $slug, int $typeId, string $context, ?int $dealId = null): int
    {
        $sourcePath = self::SOURCES[$slug];
        $destPath = 'documents/demo-coverage/' . $context . '-' . $slug . '-' . Str::random(8) . '.pdf';

        Storage::disk('local')->copy($sourcePath, $destPath);
        $size = Storage::disk('local')->size($destPath);

        $label = ucwords(str_replace('_', ' ', $slug));

        return DB::table('documents')->insertGetId([
            'original_name'     => "{$label} — {$context}.pdf",
            'storage_path'      => $destPath,
            'disk'              => 'local',
            'mime_type'         => 'application/pdf',
            'size'              => $size,
            'document_type_id'  => $typeId,
            'source_type'       => 'demo_seed',
            'deal_id'           => $dealId,
            'uploaded_by'       => $uploaderId,
            'agency_id'         => $agencyId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
