<?php

namespace App\Services\ViewingPack;

use App\Models\ViewingPack;
use App\Services\ViewingPack\Concerns\ViewingPackPdfSupport;
use Illuminate\Support\Facades\Storage;

/**
 * Buyer Pack PDF (AT-107, Step 6) — the SINGLE buyer-facing document (spec §7).
 *
 * Structure (in viewing_pack_properties.sort_order):
 *   cover → [per property: brochure page + notes page + included redacted docs] → comparison.
 *
 * This is ONE PDF. The agent sheet (Step 7) is a SEPARATE file built separately
 * and is NEVER merged here (compliance spine §1).
 *
 * Shared assembly (dompdf segments, per-property/comparison data, brochure reuse
 * with graceful fallback, pdfunite, font/image helpers) lives in
 * ViewingPackPdfSupport — the same trait the agent sheet uses (Step 9 DRY).
 *
 * INCLUSION RULE (safe default, §6/§7): a selected document is embedded ONLY if
 * it has a redacted artifact (viewing_pack_documents.redacted_file_path) whose
 * file exists on disk. A selected-but-not-yet-redacted document is NEVER embedded
 * — an un-redacted (potentially sensitive) doc can never reach the buyer.
 */
class ViewingPackBuyerPdfService
{
    use ViewingPackPdfSupport;

    /** Generate the buyer pack and return its local-disk relative path. */
    public function generate(ViewingPack $pack): string
    {
        $pack->loadMissing([
            'contact', 'agent', 'agency',
            'viewingPackProperties' => fn ($q) => $q->ordered()->with(['property', 'viewingPackDocuments']),
        ]);

        $tmpDir = sys_get_temp_dir() . '/vp_buyer_' . uniqid('', true);
        @mkdir($tmpDir, 0755, true);
        $segments = [];
        $n = 0;

        try {
            // 1. Cover
            $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes('command-center.viewing-packs.buyer-pack.cover', $this->coverData($pack)));

            // 2. Per property (in sort_order): brochure+notes, then its redacted docs.
            $seq = 0;
            foreach ($pack->viewingPackProperties as $vpp) {
                $seq++;
                $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes(
                    'command-center.viewing-packs.buyer-pack.property',
                    $this->propertyData($vpp, $seq)
                ));

                foreach ($vpp->viewingPackDocuments as $vpd) {
                    // AT-111 (Johan's ruling) — NO merge gate beyond selection: if a doc
                    // was added to the pack it is IN. Redaction is the agent's option, not
                    // an obligation — the agent who added it already owns the call (and the
                    // pack records its creator). Embed the redacted artifact when one
                    // exists, otherwise the ORIGINAL document as-is.
                    if (! $vpd->included) {
                        continue;
                    }
                    $rel = $vpd->redacted_file_path;
                    if ($rel && Storage::disk('local')->exists($rel)) {
                        $segments[] = Storage::disk('local')->path($rel);
                        continue;
                    }
                    // No redaction → the original file (buyer-pack-eligible types are PDFs).
                    $doc  = $vpd->document;
                    $disk = $doc?->disk ?: 'local';
                    if ($doc && $doc->storage_path && Storage::disk($disk)->exists($doc->storage_path)) {
                        $segments[] = Storage::disk($disk)->path($doc->storage_path);
                    }
                }
            }

            // 3. Comparison (closing) — only meaningful with 2+ properties to
            //     compare (AT-160 item 6: a single-property pack skips it).
            if ($pack->viewingPackProperties->count() > 1) {
                $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes('command-center.viewing-packs.buyer-pack.comparison', $this->comparisonData($pack)));
            }

            // 4. Concatenate everything into one PDF.
            $finalTmp = $tmpDir . '/buyer-pack.pdf';
            $this->unite($segments, $finalTmp);

            $rel = 'viewing-packs/' . $pack->id . '/buyer-pack.pdf';
            Storage::disk('local')->put($rel, (string) file_get_contents($finalTmp));

            return $rel;
        } finally {
            foreach ((array) glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    /** Generate + stream as a download. */
    public function download(ViewingPack $pack)
    {
        $rel  = $this->generate($pack);
        $name = 'VIEWING-PACK-' . ($this->buyerName($pack) ?: ('Pack-' . $pack->id)) . '.pdf';
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|]+/', ' ', $name));

        return response()->download(Storage::disk('local')->path($rel), $name);
    }

    /** Buyer-pack cover data — the only segment unique to the buyer pack. */
    private function coverData(ViewingPack $pack): array
    {
        $agent  = $pack->agent;
        $agency = $pack->agency;

        return [
            'buyerName'     => $this->buyerName($pack) ?: 'the buyer',
            'propertyCount' => $pack->viewingPackProperties->count(),
            'date'          => now()->format('j F Y'),
            'agencyName'    => $agency?->name ?: 'CoreX',
            'agentName'     => $agent?->name ?: ($agency?->name ?: ''),
            'agentPhone'    => $agent ? ($agent->cell ?? $agent->phone ?? '') : '',
            'agentEmail'    => $agent?->email ?: '',
            'logo'          => $this->publicDataUri($agency?->logo_path),
            'agentPhoto'    => $agent && method_exists($agent, 'profilePhotoUrl') ? $this->publicDataUri($agent->profilePhotoUrl()) : null,
        ];
    }
}
