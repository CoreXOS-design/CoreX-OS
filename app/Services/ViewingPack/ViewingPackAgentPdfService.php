<?php

namespace App\Services\ViewingPack;

use App\Models\ViewingPack;
use App\Models\ViewingPackDocument;
use App\Services\ViewingPack\Concerns\ViewingPackPdfSupport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Agent Sheet PDF (AT-107, Step 7) — the SEPARATE, eyes-only agent-facing
 * document (spec §8). NEVER merged with the buyer pack (compliance spine §1):
 * distinct service, distinct output path (viewing-packs/{id}/agent-sheet.pdf),
 * distinct route + download button + filename.
 *
 * Differences from the buyer pack:
 *   - Minimal header (not a branded cover) carrying a prominent
 *     "CONFIDENTIAL — AGENT EYES ONLY" band.
 *   - Per property: the SAME brochure render, but an AGENT notes block (for
 *     buyer reactions during the viewing) instead of the buyer notes block.
 *   - Documents: the ORIGINAL (UN-redacted) source of every selected/included
 *     doc — the agent sheet is eyes-only and the agent needs full information
 *     (the redacted-only rule is the BUYER pack's, not this one). See §8.
 *   - Comparison page: same table as the buyer pack (reused) for now.
 *
 * Agent-specific intel (comps / seller motivation / commission) is a named
 * future extension (§8), NOT this build.
 */
class ViewingPackAgentPdfService
{
    use ViewingPackPdfSupport;

    /** Generate the agent sheet and return its local-disk relative path. */
    public function generate(ViewingPack $pack): string
    {
        $pack->loadMissing([
            'contact', 'agent', 'agency',
            'viewingPackProperties' => fn ($q) => $q->ordered()->with(['property', 'viewingPackDocuments.document']),
        ]);

        $tmpDir = sys_get_temp_dir() . '/vp_agent_' . uniqid('', true);
        @mkdir($tmpDir, 0755, true);
        $segments = [];
        $n = 0;

        try {
            // 1. Minimal header + CONFIDENTIAL band (NOT a branded cover).
            $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes('command-center.viewing-packs.agent-sheet.header', $this->headerData($pack)));

            // 2. Per property (sort_order): brochure + AGENT notes, then the
            //    ORIGINAL (un-redacted) source of each included selected doc.
            $seq = 0;
            foreach ($pack->viewingPackProperties as $vpp) {
                $seq++;
                $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes(
                    'command-center.viewing-packs.agent-sheet.property',
                    $this->propertyData($vpp, $seq)
                ));

                foreach ($vpp->viewingPackDocuments as $vpd) {
                    if (! $vpd->included) {
                        continue;
                    }
                    // AGENT-SHEET DOC RULE: original source (un-redacted), eyes-only.
                    $segPath = $this->sourceDocSegment($vpd, $tmpDir, $n);
                    if ($segPath !== null) {
                        $segments[] = $segPath;
                        $n++;
                    }
                }
            }

            // 3. Comparison (closing) — reuse the buyer-pack comparison table. Only
            //     when there are 2+ properties to compare (AT-160 item 6).
            if ($pack->viewingPackProperties->count() > 1) {
                $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes('command-center.viewing-packs.buyer-pack.comparison', $this->comparisonData($pack)));
            }

            $finalTmp = $tmpDir . '/agent-sheet.pdf';
            $this->unite($segments, $finalTmp);

            $rel = 'viewing-packs/' . $pack->id . '/agent-sheet.pdf';
            Storage::disk('local')->put($rel, (string) file_get_contents($finalTmp));

            return $rel;
        } finally {
            foreach ((array) glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    /** Generate + stream as a download (distinct filename from the buyer pack). */
    public function download(ViewingPack $pack)
    {
        $rel  = $this->generate($pack);
        $name = 'AGENT-SHEET-' . ($this->buyerName($pack) ?: ('Pack-' . $pack->id)) . '.pdf';
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|]+/', ' ', $name));

        return response()->download(Storage::disk('local')->path($rel), $name);
    }

    private function headerData(ViewingPack $pack): array
    {
        return [
            'buyerName'     => $this->buyerName($pack) ?: 'the buyer',
            'propertyCount' => $pack->viewingPackProperties->count(),
            'date'          => now()->format('j F Y'),
            'agencyName'    => $pack->agency?->name ?: 'CoreX',
            'agentName'     => $pack->agent?->name ?: '',
        ];
    }

    /**
     * Resolve the ORIGINAL document source to a PDF segment for the agent sheet.
     * PDF source → used directly; image source → wrapped to a 1-page PDF; missing
     * or unreadable source → skipped (null), never hard-fails the sheet.
     */
    private function sourceDocSegment(ViewingPackDocument $vpd, string $tmpDir, int $i): ?string
    {
        $doc = $vpd->document;
        if (! $doc) {
            return null;
        }

        try {
            $path = Storage::disk($doc->disk ?: 'local')->path($doc->storage_path);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $path || ! is_file($path)) {
            return null;
        }

        $isPdf = str_contains(strtolower((string) $doc->mime_type), 'pdf')
            || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';

        if ($isPdf) {
            return $path; // original PDF concatenated as-is
        }

        // Image source → wrap into a 1-page PDF for concatenation.
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $mime = str_starts_with((string) $doc->mime_type, 'image/') ? $doc->mime_type : 'image/jpeg';
        $uri  = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $html = '<!doctype html><html><head><style>@page{margin:0}html,body{margin:0;padding:0}img{display:block;width:100%}</style></head>'
            . '<body><img src="' . $uri . '"></body></html>';

        $opts = ['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'dpi' => 96];
        $fontDir = $this->fontCacheDir();
        if ($fontDir !== null) {
            $opts['fontDir'] = $fontDir;
            $opts['fontCache'] = $fontDir;
        }
        $pdfBytes = (string) Pdf::setOptions($opts)->loadHTML($html)->setPaper('a4', 'portrait')->output();

        return $this->writeSegment($tmpDir, $i, $pdfBytes);
    }
}
