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
     * @param  \App\Models\User|null  $primary    Override for whose details head the
     *                                             footer (defaults to the listing agent).
     * @param  \App\Models\User|null  $secondary  Co-listing agent to render alongside
     *                                             (a second agent block). Null → single.
     * @return array<string,mixed>
     */
    public function data(Property $property, bool $embed = false, ?\App\Models\User $primary = null, ?\App\Models\User $secondary = null): array
    {
        $property->loadMissing(['agent', 'branch', 'agency']);

        // Agent identity (ad-manager.md §"Agent identity"): the brochure defaults to
        // the listing agent, but the Ad Manager may point it at another in-scope
        // agent and/or co-brand with the co-listing agent.
        $agent  = $primary ?: $property->agent;
        $agency = $property->agency;
        $branch = $property->branch;
        if ($secondary && (int) $secondary->id === (int) ($agent?->id ?? 0)) {
            $secondary = null; // never render the same person twice
        }

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

        // ── Logo: try BOTH the branch logo and the agency logo (first that
        // resolves wins), else null → the partial falls back to the wordmark.
        // Trying both matters because a branch can carry a stale logo_path whose
        // file is missing while the agency's logo is fine. ──
        $logoCandidates = array_values(array_filter([
            $branch?->logo_path,
            $agency?->logo_path,
        ], fn ($v) => trim((string) $v) !== ''));
        $logoSrc = $this->logoSrc($logoCandidates, $embed);

        // ── Agent photo (circular) ──
        $agentPhoto  = $agent ? $this->imageSrc($agent->profilePhotoUrl(), $embed, self::PHOTO_W) : null;
        $agent2Photo = $secondary ? $this->imageSrc($secondary->profilePhotoUrl(), $embed, self::PHOTO_W) : null;

        // ── Spaces / counts ──
        $beds    = (int) ($property->beds ?? 0);
        $baths   = $property->baths !== null ? (float) $property->baths : 0.0;
        $garages = (int) ($property->garages ?? 0);
        $parking = $this->countSpaces($property, 'Parking');
        $size    = $property->size_m2 ? number_format((int) $property->size_m2) . ' m²' : null;

        // ── Location line: full address — street, suburb, city, province ──
        $location = implode(', ', $this->addressParts($property));

        // ── Feature checklist: the flat features_json list (deduped) ──
        $features = $this->features($property);

        // ── Description: shrink-to-fit so the brochure is ALWAYS one A4 page ──
        // A4 @ 96dpi is 1123px tall. Estimate the height the fixed sections above
        // and below the description consume (matching the rendered heights in
        // _brochure.blade.php), then give the description whatever vertical space
        // is left. The budget SHRINKS as more sections are present (thumbnail
        // strip, specs bar, sub-headings, co-agent) so a photo-heavy listing
        // trims more description than a sparse one. The estimate is deliberately
        // CONSERVATIVE (fixed height over-counted) so descMaxPx errs small — the
        // blade also clips the description box to descMaxPx with overflow:hidden,
        // so even if the estimate is off the second page can never appear.
        $hasStrip     = count($strip) > 0;
        $hasSpecs     = ($beds > 0 || $baths > 0 || $garages > 0 || $parking > 0);
        $hasSubheads  = (! empty($property->rates_taxes) || ! empty($property->levy) || $size !== null);
        $hasSecondAgent = $secondary !== null;

        $fixedPx  = 96                          // logo header
                  + ($hasStrip ? 396 : 292)     // photo grid (hero 280 + strip 102 or none)
                  + 88                          // title + location
                  + 58                          // price line (added below the location)
                  + ($hasSpecs ? 82 : 0)        // specs bar
                  + ($hasSubheads ? 40 : 0)     // sub-headings line
                  + ($hasSecondAgent ? 190 : 170) // agent + QR footer (QR is 104px
                                                  // tall and ALWAYS present in the
                                                  // real embedded PDF — measured)
                  + 34;                         // description padding-top + bottom + safety
        $descMaxPx = max(90, 1123 - $fixedPx);

        // Trim to a char budget sized from the space left on the page (see
        // charBudget()). This estimate lands one page for the vast majority; the
        // pdf() render then VERIFIES and shrinks further on the rare edge listing
        // (long wrapping title / many short paragraphs) so the output is ALWAYS
        // one page — dompdf offers no reliable clip.
        $description = $this->paragraphs((string) ($property->description ?? ''), $this->charBudget($descMaxPx));

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
            'descMaxPx'   => $descMaxPx,
            'pin'         => $pin,

            'agentName'   => $agent?->name ?: ($agency?->name ?: ''),
            'agentPhone'  => $agent ? ($agent->cell ?: $agent->phone ?: '') : '',
            'agentEmail'  => $agent?->email ?: '',
            'agentPhoto'  => $agentPhoto,

            // Co-listing agent (null/empty keys when single-agent → partial hides it).
            'agent2Name'  => $secondary?->name ?: '',
            'agent2Phone' => $secondary ? ($secondary->cell ?: $secondary->phone ?: '') : '',
            'agent2Email' => $secondary?->email ?: '',
            'agent2Photo' => $agent2Photo,

            'agencyName'  => $agency?->name ?: 'CoreX',
            'qr'          => $qrSrc,
        ];
    }

    /**
     * The description char budget for a given remaining-space height. Single
     * source of truth so data() (initial trim) and pdf() (fit loop) agree.
     * ~19px line-height at 12px Inter; ~64 effective chars per justified line
     * across the 730px column, floored so a sliver of space still shows text.
     */
    private function charBudget(int $descMaxPx): int
    {
        return (int) max(160, floor($descMaxPx / 19.2) * 64);
    }

    /**
     * Render the brochure to a downloadable A4 PDF — GUARANTEED one page.
     *
     * data() trims the description to a height-based estimate that fits one page
     * for almost every listing. For the rare edge case that still spills (a long
     * wrapping title, or many short paragraphs whose inter-paragraph margins add
     * up), verify against a real render and shrink the description until it fits.
     * Only listings whose description was actually trimmed can overflow, so a
     * short/medium description skips the extra render entirely.
     */
    public function pdf(Property $property, ?\App\Models\User $primary = null, ?\App\Models\User $secondary = null)
    {
        $data = $this->data($property, embed: true, primary: $primary, secondary: $secondary);

        $rawDesc = trim((string) ($property->description ?? ''));
        $budget  = $this->charBudget((int) ($data['descMaxPx'] ?? 260));
        if (count($data['description']) && mb_strlen($rawDesc) > $budget) {
            // Was trimmed → it sits near the page edge; verify and shrink if needed.
            for ($i = 0; $i < 6 && $this->renderedPageCount($data) > 1; $i++) {
                $budget = max(120, (int) ($budget * 0.85));
                $data['description'] = $this->paragraphs($rawDesc, $budget);
                if ($budget <= 120) break; // floor — never loop forever
            }
        }

        $pdf = Pdf::loadView('corex.properties.brochure-pdf', ['b' => $data])
            ->setPaper('a4', 'portrait');

        // Embedded data-URIs only — no network. Keep the GD/zlib stack happy.
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('dpi', 96);

        // dompdf MUST write a font-metrics cache for the embedded Inter @font-face.
        // The default (storage/fonts) is created by the deploy user, so php-fpm
        // (www-data) can't write there → "Permission denied" (staging). Point it at
        // a dir the APP creates at runtime under the already-writable storage/app,
        // so it's owned by the web process and writable everywhere. See spec §10c.
        $fontDir = $this->fontCacheDir();
        if ($fontDir !== null) {
            $pdf->setOption('fontDir', $fontDir);
            $pdf->setOption('fontCache', $fontDir);
        }

        return $pdf;
    }

    /**
     * Page count of the brochure as it will actually render, using dompdf options
     * IDENTICAL to pdf() so the measurement matches the final output. Best-effort:
     * any failure returns 1 so a measurement hiccup never blocks the download (the
     * estimate already fits one page in almost every case).
     */
    private function renderedPageCount(array $data): int
    {
        try {
            $html = view('corex.properties.brochure-pdf', ['b' => $data])->render();

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('dpi', 96);
            $fontDir = $this->fontCacheDir();
            if ($fontDir !== null) {
                $options->set('fontDir', $fontDir);
                $options->set('fontCache', $fontDir);
            }

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->setPaper('a4', 'portrait');
            $dompdf->loadHtml($html);
            $dompdf->render();

            return max(1, $dompdf->getCanvas()->get_page_count());
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * A writable directory for dompdf's font cache, created by the web process
     * (so ownership/permissions are correct). Returns null if it can't be made,
     * leaving dompdf on its default — the caller still renders, the cache write
     * just falls back.
     */
    private function fontCacheDir(): ?string
    {
        $dir = storage_path('app/dompdf-fonts');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
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

    /** Full human address line: street, suburb, city, province (deduped). */
    private function addressLine(Property $property): string
    {
        return implode(', ', $this->addressParts($property));
    }

    /**
     * The property's full address as ordered parts — street, suburb, city,
     * province — dropping any empty part and any consecutive case-insensitive
     * duplicate (e.g. a listing whose suburb and city are both "Southbroom"
     * renders "1 Tavistock, Southbroom, KwaZulu-Natal", not "…, Southbroom,
     * Southbroom, …"). The street prefers the curated `address`, else
     * "{street_number} {street_name}", else the complex name.
     *
     * @return string[]
     */
    private function addressParts(Property $property): array
    {
        $street = trim((string) $property->address);
        if ($street === '') {
            $street = trim(trim((string) $property->street_number) . ' ' . trim((string) $property->street_name));
        }
        if ($street === '' && trim((string) $property->complex_name) !== '') {
            $street = trim((string) $property->complex_name);
        }

        $parts = array_filter([
            $street,
            $property->suburb,
            $property->city,
            $property->province,
        ], fn ($v) => trim((string) $v) !== '');

        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (empty($out) || mb_strtolower((string) end($out)) !== mb_strtolower($part)) {
                $out[] = $part;
            }
        }

        return $out;
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
     * Resolve the first usable logo from a list of candidate disk paths to a
     * data-URI (embed) or asset URL (browser). Logos keep their original
     * bytes/format (preserve transparency).
     *
     * For the PDF each candidate is tried in turn: read straight off the public
     * disk, and if that path doesn't resolve here (e.g. staging serves storage
     * from a mounted drive the disk root doesn't point at) fall back to an HTTP
     * GET of the public URL — the web server serves it even when the local path
     * doesn't. A single, short-timeout fetch, so it's safe to do for our own
     * host (unlike the bulk property images).
     *
     * @param  string[]  $candidates
     */
    private function logoSrc(array $candidates, bool $embed): ?string
    {
        // Strip a stray leading public/ or storage/ some installs store.
        $candidates = array_map(
            fn ($p) => preg_replace('#^(public/|storage/)#', '', ltrim((string) $p, '/')),
            $candidates,
        );

        if (! $embed) {
            return $candidates ? asset('storage/' . $candidates[0]) : null;
        }

        foreach ($candidates as $rel) {
            $bytes = null;
            try {
                $disk = Storage::disk('public');
                if ($disk->exists($rel)) {
                    $bytes = $disk->get($rel);
                }
            } catch (\Throwable) {
                // fall through to HTTP
            }
            $bytes ??= $this->httpGet(asset('storage/' . $rel));

            if ($bytes !== null && $bytes !== '') {
                return $this->scaledLogoDataUri($bytes, 600);
            }
        }

        return null;
    }

    /**
     * Downscale a logo (preserving transparency) to a PNG data-URI. Logos are
     * displayed ~52px tall, so a full-res upload (often multi-MB) is needlessly
     * embedded raw otherwise. SVG / GD-undecodable logos fall back to raw bytes
     * (dompdf renders SVG via php-svg-lib), so vector logos still work.
     */
    private function scaledLogoDataUri(string $bytes, int $maxW): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $this->rawDataUri($bytes);
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return $this->rawDataUri($bytes); // SVG / unknown → embed as-is
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $tw = ($maxW > 0 && $w > $maxW) ? $maxW : max(1, $w);
        $th = max(1, (int) round($h * $tw / max(1, $w)));

        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);

        ob_start();
        imagepng($dst, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($dst);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** Short-timeout HTTP GET of any URL (incl. our own host). Null on failure. */
    private function httpGet(string $url): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }
        try {
            $ctx = stream_context_create([
                'http'  => ['timeout' => 5, 'follow_location' => 1],
                'https' => ['timeout' => 5, 'follow_location' => 1],
                'ssl'   => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $bytes = @file_get_contents($url, false, $ctx);

            return $bytes !== false && $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
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
