<?php

namespace App\Services\ViewingPack;

use App\Models\Property;
use App\Models\ViewingPack;
use App\Models\ViewingPackProperty;
use App\Services\Properties\PropertyBrochureService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Buyer Pack PDF (AT-107, Step 6) — the SINGLE buyer-facing document (spec §7).
 *
 * Structure (in viewing_pack_properties.sort_order):
 *   cover → [per property: brochure page + notes page + included redacted docs] → comparison.
 *
 * This is ONE PDF. The agent sheet (Step 7) is a SEPARATE file built separately
 * and is NEVER merged here (compliance spine, §1).
 *
 * Assembly: dompdf renders the cover / per-property / comparison segments (house
 * pattern, PropertyBrochureService); the already-flattened redacted-doc PDFs from
 * Step 5b are interleaved as-is; everything is concatenated with Poppler pdfunite.
 *
 * INCLUSION RULE (safe default, §6/§7): a selected document is embedded ONLY if
 * it has a redacted artifact (viewing_pack_documents.redacted_file_path) whose
 * file exists on disk. A selected-but-not-yet-redacted document is NEVER embedded
 * — an un-redacted (potentially sensitive) doc can never reach the buyer. The pack
 * view already shows redaction status per doc, so this is visible, not silent.
 *
 * Graceful degradation (§11): if a property's brochure render is unavailable
 * (e.g. PropertyBrochureService absent on main, or it throws), the property still
 * gets a minimal page — the pack never hard-fails on one property.
 */
class ViewingPackBuyerPdfService
{
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
                    $rel = $vpd->redacted_file_path;
                    // INCLUSION RULE — redacted artifact required + must exist on disk.
                    if (! $vpd->included || ! $rel || ! Storage::disk('local')->exists($rel)) {
                        continue;
                    }
                    $segments[] = Storage::disk('local')->path($rel);
                }
            }

            // 3. Comparison (closing)
            $segments[] = $this->writeSegment($tmpDir, $n++, $this->renderToBytes('command-center.viewing-packs.buyer-pack.comparison', $this->comparisonData($pack)));

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

    // ── segment data builders ────────────────────────────────────────────

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

    private function propertyData(ViewingPackProperty $vpp, int $seq): array
    {
        $property = $vpp->property;
        $address  = $property ? ($this->addressLine($property) ?: ('Property #' . $vpp->property_id)) : ('Property #' . $vpp->property_id);

        $brochure = $property ? $this->brochureDataFor($property) : null;

        $minimal = ['price' => null, 'address' => $address, 'rows' => []];
        if (! $brochure && $property) {
            $minimal = [
                'price'   => $this->money($property->price),
                'address' => $address,
                'rows'    => [
                    'Suburb'   => $property->suburb ?: null,
                    'Type'     => $property->property_type ?: null,
                    'Beds'     => $property->beds ?: null,
                    'Baths'    => $property->baths !== null ? rtrim(rtrim((string) $property->baths, '0'), '.') : null,
                    'Garages'  => $property->garages ?: null,
                    'Size'     => $property->size_m2 ? number_format((int) $property->size_m2) . ' m²' : null,
                    'Status'   => $property->status ? Str::title(str_replace('_', ' ', $property->status)) : null,
                    'Reference' => $property->external_id ?: ('REF ' . $vpp->property_id),
                ],
            ];
        }

        return [
            'seq'      => $seq,
            'address'  => $address,
            'brochure' => $brochure,
            'minimal'  => $minimal,
        ];
    }

    private function comparisonData(ViewingPack $pack): array
    {
        $rows = [];
        $seq = 0;
        foreach ($pack->viewingPackProperties as $vpp) {
            $seq++;
            $p = $vpp->property;
            $rows[] = [
                'seq'     => $seq,
                'address' => $p ? ($this->addressLine($p) ?: ('Property #' . $vpp->property_id)) : ('Property #' . $vpp->property_id),
                'suburb'  => $p?->suburb ?: '',
                'price'   => $p ? ($this->money($p->price) ?: '—') : '—',
                'beds'    => $p && $p->beds ? (string) $p->beds : '—',
                'baths'   => $p && $p->baths !== null ? rtrim(rtrim((string) $p->baths, '0'), '.') : '—',
                'garages' => $p && $p->garages ? (string) $p->garages : '—',
                'size'    => $p && $p->size_m2 ? number_format((int) $p->size_m2) . ' m²' : '—',
            ];
        }

        return [
            'rows'       => $rows,
            'agentName'  => $pack->agent?->name ?: '',
            'agencyName' => $pack->agency?->name ?: 'CoreX',
            'date'       => now()->format('j F Y'),
        ];
    }

    /** Brochure data (embed mode) — best-effort; null if the feature is absent or errors. */
    private function brochureDataFor(Property $property): ?array
    {
        if (! class_exists(PropertyBrochureService::class)) {
            return null;
        }
        try {
            return app(PropertyBrochureService::class)->data($property, embed: true);
        } catch (\Throwable $e) {
            Log::warning('Buyer pack: brochure render unavailable for property', ['property' => $property->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function buyerName(ViewingPack $pack): string
    {
        $c = $pack->contact;
        if (! $c) {
            return '';
        }

        return trim((string) ($c->full_name ?? trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))));
    }

    private function addressLine(Property $property): string
    {
        $street = trim((string) $property->address);
        if ($street === '') {
            $street = trim(trim((string) $property->street_number) . ' ' . trim((string) $property->street_name));
        }
        $parts = array_filter([$street, $property->suburb], fn ($v) => trim((string) $v) !== '');

        return implode(', ', $parts);
    }

    private function money($price): ?string
    {
        $price = (int) $price;

        return $price > 0 ? 'R ' . number_format($price) : null;
    }

    private function renderToBytes(string $view, array $data): string
    {
        // Options MUST be set BEFORE loadView — dompdf reads font_dir/font_cache
        // at construction; setting them after loadView leaves the Inter @font-face
        // .ufm metrics writing to the (missing) default dir and crashes.
        $opts = ['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'dpi' => 96];
        $fontDir = $this->fontCacheDir();
        if ($fontDir !== null) {
            $opts['fontDir']   = $fontDir;
            $opts['fontCache'] = $fontDir;
        }

        return (string) Pdf::setOptions($opts)->loadView($view, $data)->setPaper('a4', 'portrait')->output();
    }

    private function writeSegment(string $dir, int $i, string $bytes): string
    {
        $path = $dir . '/seg-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . '.pdf';
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Concatenate PDFs with Poppler pdfunite (single source of the final pack). */
    private function unite(array $inputs, string $out): void
    {
        $inputs = array_values(array_filter($inputs, fn ($p) => is_string($p) && is_file($p)));
        if (empty($inputs)) {
            throw new \RuntimeException('No PDF segments to assemble.');
        }

        $proc = new Process(array_merge(['pdfunite'], $inputs, [$out]));
        $proc->setTimeout(180);
        $proc->run();

        if (! $proc->isSuccessful() || ! is_file($out)) {
            throw new \RuntimeException('pdfunite failed: ' . trim($proc->getErrorOutput()));
        }
    }

    /** Web-process-writable dompdf font cache dir (mirrors PropertyBrochureService). */
    private function fontCacheDir(): ?string
    {
        $dir = storage_path('app/dompdf-fonts');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /** Best-effort image → data-URI from the public disk (logos, agent photos). */
    private function publicDataUri(?string $relOrUrl): ?string
    {
        $raw = trim((string) $relOrUrl);
        if ($raw === '') {
            return null;
        }

        $path = parse_url($raw, PHP_URL_PATH) ?: $raw;
        if (str_contains($path, '/storage/')) {
            $rel = ltrim(substr($path, strpos($path, '/storage/') + 9), '/');
        } else {
            $rel = preg_replace('#^(public/|storage/)#', '', ltrim($path, '/'));
        }

        try {
            $disk = Storage::disk('public');
            if ($disk->exists($rel)) {
                $bytes = $disk->get($rel);
                $mime  = 'image/png';
                if (function_exists('finfo_open') && ($f = finfo_open(FILEINFO_MIME_TYPE))) {
                    $detected = finfo_buffer($f, $bytes);
                    finfo_close($f);
                    if (is_string($detected) && str_starts_with($detected, 'image/')) {
                        $mime = $detected;
                    }
                }

                return 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        return null;
    }
}
