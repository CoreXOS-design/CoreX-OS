<?php

namespace App\Services\ViewingPack\Concerns;

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
 * Shared PDF-assembly helpers for the Viewing Pack outputs (AT-107).
 *
 * Holds the behaviour common to the buyer pack (Step 6) and the agent sheet
 * (Step 7): dompdf segment rendering, per-property + comparison data, the
 * brochure-reuse-with-graceful-fallback, pdfunite concatenation, and font/image
 * helpers. The two output services stay SEPARATE classes (compliance spine §1 —
 * two distinct, never-merged documents) but share this support so the common
 * logic lives in one place.
 *
 * (Step 6's ViewingPackBuyerPdfService keeps its own copies — it is committed and
 * out of scope to modify here; a future cleanup can move it onto this trait too.)
 */
trait ViewingPackPdfSupport
{
    /** Per-property segment data (brochure-or-minimal) — shared shape. */
    protected function propertyData(ViewingPackProperty $vpp, int $seq): array
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
                    'Suburb'    => $property->suburb ?: null,
                    'Type'      => $property->property_type ?: null,
                    'Beds'      => $property->beds ?: null,
                    'Baths'     => $property->baths !== null ? rtrim(rtrim((string) $property->baths, '0'), '.') : null,
                    'Garages'   => $property->garages ?: null,
                    'Size'      => $property->size_m2 ? number_format((int) $property->size_m2) . ' m²' : null,
                    'Status'    => $property->status ? Str::title(str_replace('_', ' ', $property->status)) : null,
                    'Reference' => $property->external_id ?: ('REF ' . $vpp->property_id),
                ],
            ];
        }

        return ['seq' => $seq, 'address' => $address, 'brochure' => $brochure, 'minimal' => $minimal];
    }

    /** Comparison-table data — shared (same table for buyer pack + agent sheet). */
    protected function comparisonData(ViewingPack $pack): array
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

    /** Brochure data (embed mode) — best-effort; null if the feature is absent/errors. */
    protected function brochureDataFor(Property $property): ?array
    {
        if (! class_exists(PropertyBrochureService::class)) {
            return null;
        }
        try {
            return app(PropertyBrochureService::class)->data($property, embed: true);
        } catch (\Throwable $e) {
            Log::warning('Viewing pack PDF: brochure render unavailable', ['property' => $property->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function buyerName(ViewingPack $pack): string
    {
        $c = $pack->contact;
        if (! $c) {
            return '';
        }

        return trim((string) ($c->full_name ?? trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))));
    }

    protected function addressLine(Property $property): string
    {
        // Reuse the canonical display-address convention (Property::buildDisplayAddress)
        // instead of a local reinvention, so every viewing/buyer-pack surface — the web
        // panel and the generated PDFs — shows the identical address. (AT-170)
        return $property->buildDisplayAddress();
    }

    protected function money($price): ?string
    {
        $price = (int) $price;

        return $price > 0 ? 'R ' . number_format($price) : null;
    }

    protected function renderToBytes(string $view, array $data): string
    {
        // Options MUST be set BEFORE loadView (dompdf reads font_dir/font_cache at
        // construction; setting after leaves the Inter @font-face cache crashing).
        $opts = ['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'dpi' => 96];
        $fontDir = $this->fontCacheDir();
        if ($fontDir !== null) {
            $opts['fontDir']   = $fontDir;
            $opts['fontCache'] = $fontDir;
        }

        return (string) Pdf::setOptions($opts)->loadView($view, $data)->setPaper('a4', 'portrait')->output();
    }

    protected function writeSegment(string $dir, int $i, string $bytes): string
    {
        $path = $dir . '/seg-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . '.pdf';
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Concatenate PDFs with Poppler pdfunite. */
    protected function unite(array $inputs, string $out): void
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
    protected function fontCacheDir(): ?string
    {
        $dir = storage_path('app/dompdf-fonts');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /** Best-effort image → data-URI from the public disk (logos, agent photos). */
    protected function publicDataUri(?string $relOrUrl): ?string
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
