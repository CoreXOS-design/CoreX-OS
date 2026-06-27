<?php

declare(strict_types=1);

namespace App\Services\Properties;

use App\Models\Property;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Printable Brochure (Ad Manager) — A4 single-page property data sheet.
 *
 * Spec: .ai/specs/ad-manager.md §"Printable Brochure". The brochure is the
 * always-first, always-A4 template in the Ad Manager. Unlike the social-square
 * templates (rendered client-side to PNG via html2canvas), the brochure is a
 * true PDF rendered server-side with dompdf — the file is meant to be printed
 * and handed out, so it must be vector-text, A4 and print-crisp.
 *
 * The SAME Blade partial (`corex.properties._brochure`) renders in two modes:
 *   - PDF (embed=true)  — every image is a downscaled base64 data-URI so dompdf
 *     needs no remote fetching and the output is self-contained.
 *   - browser thumbnail (embed=false) — plain image URLs (fast; no GD work),
 *     used for the picker card preview on both Ad Manager surfaces.
 *
 * Pillars: Property (read), Agent (read), Agency (read/brand) — see spec §2.
 * ROBUSTNESS: every image/QR resolution is best-effort — a missing photo,
 * logo, agent picture or QR never throws; the section simply degrades.
 */
class PropertyBrochureService
{
    /** Max embedded widths (px) — keep the PDF small + dompdf fast. Sized for
     *  A4 print: hero right photo displays ~470px wide, thumbnails ~150px. */
    private const HERO_W  = 520;
    private const THUMB_W = 220;
    private const PHOTO_W = 160;

    /**
     * Build the brochure data array consumed by the `_brochure` partial.
     *
     * @param  bool  $embed  true → images become base64 data-URIs (PDF);
     *                       false → plain URLs (browser thumbnail).
     * @return array<string,mixed>
     */
    public function data(Property $property, bool $embed = false): array
    {
        $property->loadMissing(['agent', 'branch', 'agency']);

        $agent  = $property->agent;
        $agency = $property->agency;
        $branch = $property->branch;

        // ── Images: 2 hero photos (40% / 60%) + a 5-photo thumbnail strip ──
        // For the PDF we fetch each unique source ONCE — no double network/IO.
        $images = $property->displayImages();
        $hero   = array_slice($images, 0, 2);
        $strip  = array_slice($images, 2, 5);

        if ($embed) {
            $bytesByUrl = [];
            foreach (array_merge($hero, $strip) as $u) {
                $bytesByUrl[$u] ??= $this->readImageBytes($u);
            }
            $heroSrc  = array_map(fn ($u) => $this->scaledDataUri($bytesByUrl[$u] ?? null, self::HERO_W), $hero);
            $stripSrc = array_map(fn ($u) => $this->scaledDataUri($bytesByUrl[$u] ?? null, self::THUMB_W), $strip);
        } else {
            // Browser thumbnails just use the plain URLs (no fetch, no GD).
            $heroSrc  = $hero;
            $stripSrc = $strip;
        }

        // ── Logo: branch → agency, else null (partial falls back to wordmark) ──
        $logoPath = $branch?->logo_path ?: $agency?->logo_path;
        $logoSrc  = $logoPath ? $this->logoSrc($logoPath, $embed) : null;

        // ── Agent photo (circular) ──
        $agentPhoto = $agent ? $this->imageSrc($agent->profilePhotoUrl(), $embed, self::PHOTO_W) : null;

        // ── Spaces / counts ──
        $beds    = (int) ($property->beds ?? 0);
        $baths   = $property->baths !== null ? (float) $property->baths : 0.0;
        $garages = (int) ($property->garages ?? 0);
        $parking = $this->countSpaces($property, 'Parking');
        $size    = $property->size_m2 ? number_format((int) $property->size_m2) . ' m²' : null;

        // ── Location line: suburb, city, province (whatever's present) ──
        $location = implode(', ', array_filter([
            $property->suburb,
            $property->city,
            $property->province,
        ], fn ($v) => trim((string) $v) !== ''));

        // ── Feature checklist: the flat features_json list (deduped) ──
        $features = $this->features($property);

        // ── Description: split into paragraphs, capped so the brochure stays
        // one A4 page (the full listing is one QR scan away). ──
        $description = $this->paragraphs((string) ($property->description ?? ''), 900);

        // ── Location pin — a GD-drawn PNG (not an inline SVG, which dompdf/browsers
        // clip at the text baseline). Raster → predictable, identical in both hosts. ──
        $pin = $this->pinDataUri();

        // ── QR → public shareable preview of the listing ──
        // Only fetched for the real PDF (embed). Browser thumbnails skip it so a
        // page/preview render never blocks on the external QR service.
        $qrSrc = $embed ? $this->qrSrc($this->previewUrl($property)) : null;

        return [
            'price'       => $property->formattedPrice(),
            'title'       => trim((string) $property->title) ?: 'Property for Sale',
            'location'    => $location,
            'reference'   => $property->external_id ?: ('REF ' . $property->id),
            'status'      => $property->adData()['status_badge'] ?? 'FOR SALE',

            'heroImages'  => array_values(array_filter($heroSrc)),
            'stripImages' => array_values(array_filter($stripSrc)),
            'logo'        => $logoSrc,

            'beds'        => $beds,
            'baths'       => $baths,
            'garages'     => $garages,
            'parking'     => $parking,
            'size'        => $size,

            'rates'       => $property->rates_taxes ? 'R ' . number_format((int) $property->rates_taxes) : null,
            'levy'        => $property->levy ? 'R ' . number_format((int) $property->levy) : null,

            'features'    => $features,
            'description' => $description,
            'pin'         => $pin,

            'agentName'   => $agent?->name ?: ($agency?->name ?: ''),
            'agentPhone'  => $agent ? ($agent->cell ?: $agent->phone ?: '') : '',
            'agentEmail'  => $agent?->email ?: '',
            'agentPhoto'  => $agentPhoto,
            'agencyName'  => $agency?->name ?: 'CoreX',
            'qr'          => $qrSrc,
        ];
    }

    /**
     * Render the brochure to a downloadable A4 PDF.
     */
    public function pdf(Property $property)
    {
        $data = $this->data($property, embed: true);

        $pdf = Pdf::loadView('corex.properties.brochure-pdf', ['b' => $data])
            ->setPaper('a4', 'portrait');

        // Embedded data-URIs only — no network. Keep the GD/zlib stack happy.
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('dpi', 96);

        return $pdf;
    }

    /** A filesystem-safe download name: "Brochure - {address}.pdf". */
    public function filename(Property $property): string
    {
        $address = $this->addressLine($property);
        // Keep spaces/commas readable; strip only characters illegal in filenames.
        $address = trim((string) preg_replace('/[\/\\\\:*?"<>|]+/', ' ', $address));
        $address = trim((string) preg_replace('/\s+/', ' ', $address));
        if ($address === '') {
            $address = 'Property ' . $property->id;
        }

        return 'Brochure - ' . $address . '.pdf';
    }

    /** Full human address line: street, suburb, city, province (whatever's present). */
    private function addressLine(Property $property): string
    {
        return implode(', ', array_filter([
            $property->address,
            $property->suburb,
            $property->city,
            $property->province,
        ], fn ($v) => trim((string) $v) !== ''));
    }

    // ── image resolution ────────────────────────────────────────────────

    /**
     * Resolve a (possibly external) image URL to either a base64 data-URI
     * (embed) or the plain URL (browser). Returns null when unresolvable.
     */
    private function imageSrc(?string $url, bool $embed, int $maxW): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! $embed) {
            return $url;
        }

        $bytes = $this->readImageBytes($url);
        if ($bytes === null) {
            return null;
        }

        return $this->scaledDataUri($bytes, $maxW);
    }

    /**
     * Resolve an agency/branch logo path to a data-URI (embed) or asset URL.
     * Logos keep their original bytes/format (preserve transparency). For the
     * PDF we read straight off the public disk; if the stored path doesn't
     * resolve there we fall back to the asset URL (covers paths stored with a
     * host or odd prefix).
     */
    private function logoSrc(string $diskPath, bool $embed): ?string
    {
        $rel = ltrim($diskPath, '/');
        if (! $embed) {
            return asset('storage/' . $rel);
        }

        $bytes = null;
        try {
            $disk = Storage::disk('public');
            if ($disk->exists($rel)) {
                $bytes = $disk->get($rel);
            }
        } catch (\Throwable) {
            // fall through
        }
        $bytes ??= $this->readImageBytes(asset('storage/' . $rel));

        return $bytes !== null ? $this->rawDataUri($bytes) : null;
    }

    /**
     * Read raw image bytes for a URL — local public disk first, then (only for a
     * genuinely EXTERNAL host) a best-effort remote fetch.
     *
     * Crucially we NEVER remote-fetch our own host: a `/storage/...` URL on the
     * app's own domain whose file is missing locally returns null instantly
     * rather than hanging on an HTTP round-trip to ourselves (a dead `localhost`
     * dev server, or a request the web worker would make back into itself).
     */
    private function readImageBytes(string $url): ?string
    {
        // Local public-disk file (any /storage/... URL).
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if ($path !== '' && str_contains($path, '/storage/')) {
            $rel = ltrim(substr($path, strpos($path, '/storage/') + 9), '/');
            try {
                $disk = Storage::disk('public');
                if ($disk->exists($rel)) {
                    return $disk->get($rel);
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // Own host with no local file → skip; do not fetch ourselves.
        if ($this->isOwnHost($url)) {
            return null;
        }

        // Genuinely external (e.g. a portal CDN) — best-effort, short timeout.
        if (preg_match('#^https?://#i', $url)) {
            try {
                $ctx = stream_context_create([
                    'http'  => ['timeout' => 4],
                    'https' => ['timeout' => 4],
                ]);
                $bytes = @file_get_contents($url, false, $ctx);

                return $bytes !== false ? $bytes : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** Is this URL on the app's own host (or a local/relative one)? */
    private function isOwnHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true; // relative URL → our own host
        }
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return true;
        }
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $appHost !== '' && $host === $appHost;
    }

    /** Downscale (GD) to maxW and return a JPEG data-URI. Null on null/decode failure. */
    private function scaledDataUri(?string $bytes, int $maxW): ?string
    {
        if ($bytes === null || $bytes === '') {
            return null;
        }
        if (! function_exists('imagecreatefromstring')) {
            return $this->rawDataUri($bytes);
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            // GD can't decode it (e.g. a .webp on a GD build without webp).
            // dompdf still renders webp/png/jpeg natively, so embed the raw
            // bytes rather than dropping the image.
            return $this->rawDataUri($bytes);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($maxW > 0 && $w > $maxW && $w > 0) {
            $nh  = max(1, (int) round($h * $maxW / $w));
            $dst = imagecreatetruecolor($maxW, $nh);
            // White matte so any transparency flattens cleanly onto the page.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $maxW, $nh, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, 74);
        $out = (string) ob_get_clean();
        imagedestroy($src);

        return 'data:image/jpeg;base64,' . base64_encode($out);
    }

    /** Embed bytes verbatim as a data-URI, sniffing the mime. */
    private function rawDataUri(string $bytes): ?string
    {
        $mime = 'image/png';
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $f ? finfo_buffer($f, $bytes) : false;
            if ($f) {
                finfo_close($f);
            }
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mime = $detected;
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    // ── QR ──────────────────────────────────────────────────────────────

    /** QR PNG data-URI for the listing URL (cached 1 day). Null on failure. */
    private function qrSrc(string $target): ?string
    {
        if ($target === '') {
            return null;
        }

        return Cache::remember('brochure-qr:' . md5($target), now()->addDay(), function () use ($target) {
            $endpoint = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=4&ecc=M&format=png&data='
                . urlencode($target);
            try {
                $ctx   = stream_context_create(['http' => ['timeout' => 6], 'https' => ['timeout' => 6]]);
                $bytes = @file_get_contents($endpoint, false, $ctx);
                if ($bytes === false || $bytes === '') {
                    return null;
                }

                return 'data:image/png;base64,' . base64_encode($bytes);
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Draw a small grey map-pin as a PNG data-URI (GD). Used inline next to the
     * location text. A raster image sizes/clips predictably in dompdf and the
     * browser alike — unlike an inline SVG, whose point gets clipped at the text
     * baseline. Drawn at 4× then downsampled for a smooth edge.
     */
    private function pinDataUri(): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagefilledpolygon')) {
            return null;
        }

        $s = 4;                 // supersample factor
        $w = 28; $h = 36;       // logical pin box
        $W = $w * $s; $H = $h * $s;

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // transparent
        imagealphablending($im, true);

        $grey = imagecolorallocate($im, 107, 107, 107); // #6b6b6b
        // Head (circle) + downward point (triangle) = teardrop.
        imagefilledellipse($im, 14 * $s, 13 * $s, 22 * $s, 22 * $s, $grey);
        imagefilledpolygon($im, array_map(fn ($v) => $v * $s, [4, 18, 24, 18, 14, 35]), $grey);
        // Hole — punch a transparent disc in the head.
        imagealphablending($im, false);
        imagefilledellipse($im, 14 * $s, 12 * $s, 9 * $s, 9 * $s, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        imagedestroy($im);

        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();
        imagedestroy($out);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** Public, shareable preview URL for the QR target. */
    private function previewUrl(Property $property): string
    {
        try {
            return route('corex.properties.preview', $property);
        } catch (\Throwable) {
            return url('/');
        }
    }

    // ── data helpers ────────────────────────────────────────────────────

    /** Sum of `count` over spaces of the given type in spaces_json. */
    private function countSpaces(Property $property, string $type): int
    {
        $sj   = $property->spaces_json ?? [];
        $list = $sj['spaces'] ?? (isset($sj[0]) ? $sj : []);

        $sum = collect($list)
            ->where('type', $type)
            ->sum(fn ($s) => (float) ($s['count'] ?? 1));

        return (int) round($sum);
    }

    /**
     * Flat, deduped feature list for the checklist (features_json). Long lists
     * are capped so the single page never overflows — the cap is logged via the
     * `more` count the partial can surface (no silent truncation, per BUILD_STANDARD).
     *
     * @return array{items: string[], more: int}
     */
    private function features(Property $property): array
    {
        $all = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) ($property->features_json ?? [])),
            fn ($v) => $v !== '',
        )));

        $cap   = 18; // + Rates/Levy = up to 20 cells → 5 rows of 4, fits the page
        $items = array_map(
            // Truncate long labels with an ellipsis so a feature never wraps a cell.
            fn ($v) => mb_strlen($v) > 24 ? rtrim(mb_substr($v, 0, 23)) . '…' : $v,
            array_slice($all, 0, $cap),
        );
        $more  = max(0, count($all) - $cap);

        return ['items' => array_values($items), 'more' => $more];
    }

    /**
     * Split a description into trimmed paragraphs on blank lines, capped to a
     * total character budget so the brochure stays a single A4 page. The last
     * kept paragraph is trimmed at a word boundary with an ellipsis when the
     * budget is hit (the QR links to the full listing).
     */
    private function paragraphs(string $text, int $maxChars = 0): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }

        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/', $text) ?: [$text]),
            fn ($p) => $p !== '',
        ));

        if ($maxChars <= 0) {
            return $parts;
        }

        $out = [];
        $used = 0;
        foreach ($parts as $p) {
            if ($used >= $maxChars) {
                break;
            }
            if ($used + mb_strlen($p) <= $maxChars) {
                $out[] = $p;
                $used += mb_strlen($p);
                continue;
            }
            // Trim this paragraph to the remaining budget at a word boundary.
            $slice = mb_substr($p, 0, max(0, $maxChars - $used));
            $cut   = mb_strrpos($slice, ' ');
            if ($cut !== false && $cut > 40) {
                $slice = mb_substr($slice, 0, $cut);
            }
            $out[] = rtrim($slice, " ,.;:") . '…';
            break;
        }

        return $out;
    }
}
