<?php

namespace App\Services\ViewingPack;

use App\Models\User;
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

    /**
     * Generate the buyer pack and return its local-disk relative path.
     * $coverAgent (AT-509): the agent whose details go on the cover when the
     * downloading agent chose "my details"; null = ruling D (the buyer's agent).
     */
    public function generate(ViewingPack $pack, ?User $coverAgent = null): string
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
            $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes('command-center.viewing-packs.buyer-pack.cover', $this->coverData($pack, $coverAgent)));

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
                    // No redaction → the original file. Eligible docs may be PDFs OR photos
                    // (JPG/PNG); pdfunite only accepts PDFs, so images are wrapped in a page.
                    $doc  = $vpd->document;
                    $disk = $doc?->disk ?: 'local';
                    if ($doc && $doc->storage_path && Storage::disk($disk)->exists($doc->storage_path)) {
                        $abs   = Storage::disk($disk)->path($doc->storage_path);
                        $bytes = (string) @file_get_contents($abs);
                        if (str_starts_with($bytes, '%PDF')) {
                            $segments[] = $abs;
                        } elseif (($img = $this->imageDataUri($bytes)) !== null) {
                            $segments[] = $this->writeSegment($tmpDir, $n++, $this->imagePageBytes($img));
                        } else {
                            \Log::warning('Viewing pack buyer PDF: skipped unreadable attachment', ['pack' => $pack->id, 'document_id' => $doc->id]);
                        }
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
    public function download(ViewingPack $pack, ?User $coverAgent = null)
    {
        $rel  = $this->generate($pack, $coverAgent);
        $name = 'VIEWING-PACK-' . ($this->buyerName($pack) ?: ('Pack-' . $pack->id)) . '.pdf';
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|]+/', ' ', $name));

        return response()->download(Storage::disk('local')->path($rel), $name);
    }

    /**
     * The buyer's CURRENT primary agent (the agent the contact is under), or null
     * when the buyer has none. Ruling D's cover agent, and the agent the
     * "whose details?" choice (AT-509) is offered against.
     */
    public function buyersAgent(ViewingPack $pack): ?User
    {
        $primaryAgentId = $pack->contact_id
            ? \App\Models\Contact::withoutGlobalScopes()->where('id', $pack->contact_id)->value('agent_id')
            : null;

        return $primaryAgentId ? User::withoutGlobalScopes()->find($primaryAgentId) : null;
    }

    /** Buyer-pack cover data — the only segment unique to the buyer pack. */
    private function coverData(ViewingPack $pack, ?User $coverAgent = null): array
    {
        // Ruling D (2026-10-07): the cover shows the buyer's CURRENT primary agent, read
        // when the PDF is built — not the pack's stored agent_id (the agent it was
        // prepared by), which kept printing the old agent after a manager moved the
        // buyer. The pack's own agent remains the fallback when the buyer has none.
        // AT-509: an explicit $coverAgent ("show my details") wins over both.
        $agent  = $coverAgent ?? $this->buyersAgent($pack) ?? $pack->agent;
        $agency = $pack->agency;

        return [
            'buyerName'     => $this->buyerName($pack) ?: 'the buyer',
            'propertyCount' => $pack->viewingPackProperties->count(),
            'date'          => now()->format('j F Y'),
            'agencyName'    => $agency?->name ?: \App\Support\ClientBrand::NEUTRAL,
            'agentName'     => $agent?->name ?: ($agency?->name ?: ''),
            'agentPhone'    => $agent ? ($agent->cell ?? $agent->phone ?? '') : '',
            'agentEmail'    => $agent?->email ?: '',
            'logo'          => $this->publicDataUri($agency?->logo_path),
            'agentPhoto'    => $agent && method_exists($agent, 'profilePhotoUrl') ? $this->publicDataUri($agent->profilePhotoUrl()) : null,
            // .ai/specs/viewing-pack.md §14 — the agency's selected cover style + its inputs.
            'cover'         => app(ViewingPackCoverService::class)->dataFor($agency, $agent),
        ];
    }

    /** Re-encode any GD-readable image as a PNG data URI, or null if it is not an image. */
    private function imageDataUri(string $bytes): ?string
    {
        $img = $bytes !== '' ? @imagecreatefromstring($bytes) : false;
        if (! $img) {
            return null;
        }
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        @imagedestroy($img);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** One A4 page with the image scaled to fit. */
    private function imagePageBytes(string $dataUri): string
    {
        $html = '<html><body style="margin:0;text-align:center"><img src="' . $dataUri . '" style="max-width:190mm;max-height:277mm;margin-top:10mm"></body></html>';

        return (string) \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
    }
}
