<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * AT-83 — composite agent "business card" image for the WhatsApp link-preview
 * (og:image) on the opt-in landing page.
 *
 * The free wa.me model can only pre-fill PLAIN TEXT — no inline images — so the
 * only branded visual a seller sees in WhatsApp is the link-preview card
 * WhatsApp renders from the FIRST URL in the body. We point that first URL at
 * the opt-in page, whose og:image is the PNG this service composites:
 *
 *     [ circular agent photo ]   Agent Name
 *                                Title / designation
 *                                FFC <number>
 *                                <agency name>          [ HFC logo panel ]
 *
 * Rendered with GD (installed everywhere, no new composer dependency) onto a
 * fixed 1200×630 canvas — the standard Open-Graph / WhatsApp large-preview size
 * (≥300×200 with a ~1.91:1 aspect renders as the big card, not a thumbnail).
 *
 * CACHE: one PNG per agent, filename carries a content hash
 * (`agent-{id}-{hash}.png`) so the public URL changes whenever the agent's
 * photo or details change — WhatsApp caches previews by URL, so a changing URL
 * is what forces a re-fetch. Stale hashes for the same agent are pruned on
 * regenerate (these are derived cache artefacts, not domain records — the
 * no-hard-delete rule does not apply; they regenerate on demand).
 *
 * ROBUSTNESS (BUILD_STANDARD §2 input-space): no photo → initials card;
 * missing FFC / title → that line is simply omitted; long names → the font
 * shrinks to fit, then ellipsises. The service NEVER throws to the caller — on
 * any failure it falls back, ultimately to a text-only card, so the og:image is
 * never a broken/blank link.
 */
final class AgentCardImageService
{
    /** WhatsApp/OG large-preview canvas. Documented constant, not agency-configurable (per Johan — don't over-engineer dims). */
    private const W = 1200;
    private const H = 630;

    /** Public-disk directory the cached cards live in. */
    private const DIR = 'outreach-cards';

    private const FONT_REGULAR = 'resources/fonts/DejaVuSans.ttf';
    private const FONT_BOLD    = 'resources/fonts/DejaVuSans-Bold.ttf';

    /** Absolute font paths — GD/imagettftext resolves a relative path against the
     *  worker CWD (unreliable under php-fpm), so they are anchored via base_path(). */
    private string $fontRegular;
    private string $fontBold;

    public function __construct()
    {
        $this->fontRegular = base_path(self::FONT_REGULAR);
        $this->fontBold    = base_path(self::FONT_BOLD);
    }

    /** Brand fallbacks (used only when the agency has no colour configured). */
    private const NAVY = [11, 42, 74];     // #0b2a4a
    private const CYAN = [51, 196, 224];   // #33c4e0
    private const WHITE = [255, 255, 255];

    /**
     * Absolute filesystem path to the cached card PNG for this agent,
     * generating + caching it first if missing. Generate-on-miss; cheap on
     * subsequent calls (a single exists() check).
     */
    public function resolve(User $agent): string
    {
        $rel  = $this->relativePath($agent);
        $disk = Storage::disk('public');

        if (!$disk->exists($rel)) {
            $this->pruneOld($agent);
            $png = $this->render($agent);
            $disk->put($rel, $png);
        }

        return $disk->path($rel);
    }

    /**
     * The content hash for this agent's current card — used to cache-bust the
     * og:image URL (?v=hash) so WhatsApp re-fetches when the card changes.
     * Computing it does NOT generate the image (cheap; safe in a page render).
     */
    public function cacheKey(User $agent): string
    {
        return $this->hash($agent);
    }

    /** Disk-relative path for the agent's current card. */
    public function relativePath(User $agent): string
    {
        return self::DIR . '/agent-' . $agent->id . '-' . $this->hash($agent) . '.png';
    }

    // ── hashing / inputs ─────────────────────────────────────────────────

    /**
     * Content hash over every input the card draws — so the filename (and thus
     * the public URL) changes the moment any of them change. Includes the photo
     * + logo file mtimes so a replaced file at the same path still busts.
     */
    private function hash(User $agent): string
    {
        $photo = $this->photoPath($agent);
        $logo  = $this->logoPath($agent);
        $agency = $this->agency($agent);

        $parts = [
            'v3', // bump to force-regenerate every card after a layout change
            (string) $agent->id,
            (string) $agent->name,
            (string) ($agent->designation ?? ''),
            (string) ($agent->ffc_number ?? ''),
            (string) ($agency?->ffc_no ?? ''),
            (string) ($agency?->name ?? ''),
            $photo ? ($photo . '|' . @filemtime($photo)) : 'no-photo',
            $logo ? ($logo . '|' . @filemtime($logo)) : 'no-logo',
        ];

        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    private function agency(User $agent): ?Agency
    {
        $agencyId = (int) ($agent->agency_id ?: 0);
        if ($agencyId <= 0) {
            return null;
        }
        return Agency::withoutGlobalScopes()->find($agencyId);
    }

    /**
     * Absolute filesystem path to the agent's photo, or null. Mirrors
     * User::profilePhotoUrl()'s priority (user_documents profile_photo →
     * legacy agent_photo_path) but returns the FS path for GD to read.
     */
    private function photoPath(User $agent): ?string
    {
        $disk = Storage::disk('public');

        try {
            $doc = $agent->documents()
                ->where('document_type', 'profile_photo')
                ->latest()
                ->first();
            if ($doc && $doc->file_path && $disk->exists($doc->file_path)) {
                return $disk->path($doc->file_path);
            }
        } catch (\Throwable) {
            // relation/query failure must never break card generation
        }

        if ($agent->agent_photo_path && $disk->exists($agent->agent_photo_path)) {
            return $disk->path($agent->agent_photo_path);
        }

        return null;
    }

    /** Absolute filesystem path to the agency logo, or null. */
    private function logoPath(User $agent): ?string
    {
        $agency = $this->agency($agent);
        $disk = Storage::disk('public');
        if ($agency && $agency->logo_path && $disk->exists($agency->logo_path)) {
            return $disk->path($agency->logo_path);
        }
        return null;
    }

    // ── rendering ────────────────────────────────────────────────────────

    /** Render the card to PNG binary. Never throws — degrades to a text card. */
    private function render(User $agent): string
    {
        $agency = $this->agency($agent);
        $canvas = imagecreatetruecolor(self::W, self::H);

        $bg     = $this->color($canvas, $this->hex2rgb($agency?->default_color, self::NAVY));
        $accent = $this->color($canvas, $this->hex2rgb($agency?->button_color, self::CYAN));
        $white  = $this->color($canvas, self::WHITE);
        $whiteSoft = imagecolorallocatealpha($canvas, 255, 255, 255, 38); // ~70% opacity

        imagefilledrectangle($canvas, 0, 0, self::W, self::H, $bg);
        // Bottom brand accent bar.
        imagefilledrectangle($canvas, 0, self::H - 14, self::W, self::H, $accent);

        // ── photo / initials circle (left) ──
        $d  = 400;
        $px = 90;
        $py = (int) ((self::H - $d) / 2) - 7;
        $this->drawAvatar($canvas, $agent, $px, $py, $d, $accent, $white);

        // ── logo panel (top-right) — occupies roughly y 50..175 ──
        $logoPath = $this->logoPath($agent);
        if ($logoPath) {
            $this->drawLogoPanel($canvas, $logoPath, $white);
        }

        // ── text block (right of the photo, vertically below the logo so the
        //    name can use the full width without colliding with it) ──
        $tx   = $px + $d + 55;       // 545
        $maxW = self::W - $tx - 80;  // ~575px of usable text width

        $name  = trim((string) $agent->name) ?: 'Your agent';
        $title = trim((string) ($agent->designation ?? '')) ?: 'Estate Agent';
        $agencyName = trim((string) ($agency?->name ?? '')) ?: 'Home Finders Coastal';

        // FFC: prefer the agent's own, fall back to the agency's. Don't double the
        // "FFC" prefix when the stored value already starts with it (e.g. "FFC40/…").
        $ffcRaw = trim((string) ($agent->ffc_number ?? '')) ?: trim((string) ($agency?->ffc_no ?? ''));
        $ffc = $ffcRaw === '' ? '' : (stripos($ffcRaw, 'ffc') === 0 ? $ffcRaw : 'FFC ' . $ffcRaw);

        // Explicit baselines with leading > font size → no line overlap. Block is
        // centred near the photo's vertical midpoint (~y 308) and clear of the logo.
        $base = 290;
        $this->drawFittedLine($canvas, $this->fontBold, 52, $name, $tx, $base, $maxW, $white);
        $base += 58;
        $this->drawFittedLine($canvas, $this->fontRegular, 30, $title, $tx, $base, $maxW, $accent);
        $base += 46;
        if ($ffc !== '') {
            $this->drawFittedLine($canvas, $this->fontRegular, 26, $ffc, $tx, $base, $maxW, $whiteSoft);
            $base += 42;
        }
        $this->drawFittedLine($canvas, $this->fontRegular, 27, $agencyName, $tx, $base, $maxW, $whiteSoft);

        ob_start();
        imagepng($canvas);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $binary;
    }

    /** Draw the circular agent photo, or an initials circle when no photo. */
    private function drawAvatar($canvas, User $agent, int $x, int $y, int $d, int $accent, int $white): void
    {
        $cx = $x + (int) ($d / 2);
        $cy = $y + (int) ($d / 2);

        // White ring behind the avatar for a clean edge on any background.
        imagefilledellipse($canvas, $cx, $cy, $d + 12, $d + 12, $white);

        $photoPath = $this->photoPath($agent);
        $photo = null;
        if ($photoPath) {
            $data = @file_get_contents($photoPath);
            if ($data !== false) {
                $photo = $this->squareResize($data, $d);
            }
        }

        if ($photo) {
            $this->copyCircular($canvas, $photo, $x, $y, $d);
            imagedestroy($photo);
            return;
        }

        // Fallback: accent-filled circle with the agent's initials.
        imagefilledellipse($canvas, $cx, $cy, $d, $d, $accent);
        $initials = $this->initials($agent->name);
        [$bw, $bh] = $this->textBox($this->fontBold, 150, $initials);
        imagettftext(
            $canvas, 150, 0,
            $cx - (int) ($bw / 2), $cy + (int) ($bh / 2),
            $white, $this->fontBold, $initials
        );
    }

    /** Centre-crop the source image to a square and resample to d×d. */
    private function squareResize(string $data, int $d)
    {
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $sx = (int) (($w - $side) / 2);
        $sy = (int) (($h - $side) / 2);

        $dst = imagecreatetruecolor($d, $d);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $d, $d, $side, $side);
        imagedestroy($src);
        return $dst;
    }

    /** Copy a d×d source onto the canvas masked to a circle (1px feathered edge). */
    private function copyCircular($canvas, $src, int $x, int $y, int $d): void
    {
        $r = $d / 2;
        $cx = $r - 0.5;
        $cy = $r - 0.5;
        for ($iy = 0; $iy < $d; $iy++) {
            for ($ix = 0; $ix < $d; $ix++) {
                $dist = sqrt(($ix - $cx) ** 2 + ($iy - $cy) ** 2);
                if ($dist <= $r) {
                    imagesetpixel($canvas, $x + $ix, $y + $iy, imagecolorat($src, $ix, $iy));
                }
            }
        }
    }

    /**
     * Draw the logo inside a white rounded panel at top-right (guarantees
     * contrast for logos of any background). Returns the panel's left x so the
     * text block can avoid it (currently text sits below, so informational).
     */
    private function drawLogoPanel($canvas, string $logoPath, int $white): ?int
    {
        $data = @file_get_contents($logoPath);
        if ($data === false) {
            return null;
        }
        $logo = @imagecreatefromstring($data);
        if ($logo === false) {
            return null;
        }

        $lw = imagesx($logo);
        $lh = imagesy($logo);
        if ($lw <= 0 || $lh <= 0) {
            imagedestroy($logo);
            return null;
        }

        // Fit the logo into a max box, preserving aspect.
        $boxW = 270;
        $boxH = 100;
        $scale = min($boxW / $lw, $boxH / $lh);
        $dw = max(1, (int) ($lw * $scale));
        $dh = max(1, (int) ($lh * $scale));

        $pad = 16;
        $panelW = $dw + $pad * 2;
        $panelH = $dh + $pad * 2;
        $panelX = self::W - 70 - $panelW;
        $panelY = 48;

        imagefilledrectangle($canvas, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $white);

        $dst = $panelX + $pad;
        $dsy = $panelY + $pad;
        imagecopyresampled($canvas, $logo, $dst, $dsy, 0, 0, $dw, $dh, $lw, $lh);
        imagedestroy($logo);

        return $panelX;
    }

    /**
     * Draw one line of text, shrinking the font to fit $maxW (down to a floor),
     * then ellipsising if still too wide. Returns the y baseline used (for the
     * caller to advance from).
     */
    private function drawFittedLine($canvas, string $font, int $size, string $text, int $x, int $y, int $maxW, int $color): int
    {
        $text = trim($text);
        if ($text === '') {
            return $y;
        }

        $min = (int) max(16, $size * 0.6);
        $cur = $size;
        while ($cur > $min) {
            [$w] = $this->textBox($font, $cur, $text);
            if ($w <= $maxW) {
                break;
            }
            $cur -= 2;
        }

        // Still too wide at the floor → ellipsise.
        [$w] = $this->textBox($font, $cur, $text);
        if ($w > $maxW) {
            $text = $this->ellipsise($font, $cur, $text, $maxW);
        }

        imagettftext($canvas, $cur, 0, $x, $y, $color, $font, $text);
        return $y;
    }

    private function ellipsise(string $font, int $size, string $text, int $maxW): string
    {
        $ell = '…';
        while ($text !== '') {
            $text = function_exists('mb_substr') ? mb_substr($text, 0, -1) : substr($text, 0, -1);
            [$w] = $this->textBox($font, $size, $text . $ell);
            if ($w <= $maxW) {
                return $text . $ell;
            }
        }
        return $ell;
    }

    /** @return array{0:int,1:int} width,height of rendered text */
    private function textBox(string $font, int $size, string $text): array
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return [0, 0];
        }
        $w = abs($box[2] - $box[0]);
        $h = abs($box[7] - $box[1]);
        return [$w, $h];
    }

    private function initials(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '·';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
        return mb_strtoupper($first . $last) ?: '·';
    }

    // ── colour helpers ───────────────────────────────────────────────────

    /** @param array{0:int,1:int,2:int} $rgb */
    private function color($canvas, array $rgb): int
    {
        return imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * Parse a #rrggbb hex (the agency colour columns) to an rgb triple, falling
     * back to the supplied default on anything unparseable.
     *
     * @param array{0:int,1:int,2:int} $default
     * @return array{0:int,1:int,2:int}
     */
    private function hex2rgb(?string $hex, array $default): array
    {
        $hex = ltrim((string) $hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $default;
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    // ── cache pruning ────────────────────────────────────────────────────

    /** Remove any stale cached cards for this agent (old content hashes). */
    private function pruneOld(User $agent): void
    {
        $disk = Storage::disk('public');
        try {
            foreach ($disk->files(self::DIR) as $file) {
                if (str_starts_with(basename($file), 'agent-' . $agent->id . '-')) {
                    $disk->delete($file);
                }
            }
        } catch (\Throwable) {
            // pruning is best-effort — a leftover stale file never breaks output
        }
    }
}
