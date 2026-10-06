<?php

namespace App\Services\ViewingPack;

use App\Models\Agency;
use App\Models\User;
use App\Models\ViewingPackCoverAuditEntry;
use App\Services\ViewingPack\Concerns\ViewingPackPdfSupport;

/**
 * Viewing Pack cover style (.ai/specs/viewing-pack.md §14).
 *
 * One place that decides WHAT the cover shows, for both the PDF
 * (ViewingPackBuyerPdfService::coverData) and the settings-page "Cover preview", so the
 * two cannot drift. It draws only on data CoreX already holds — agency logo, tagline /
 * website / phone, the agency colour roles, and the agent's name, cell, email and
 * (cut-out) photo — plus the agency's own cover settings. Nothing agency-specific is coded:
 * every agency gets the same neutral defaults.
 *
 *  - style 'standard'        today's cover, unchanged (the default for every agency).
 *  - style 'classic_welcome' white page, navy band down the right edge with the slogan /
 *                            website / office phone rotated bottom-to-top, logo across the
 *                            top, "WELCOME TO / YOUR / VIEWING DAY", the agent's cut-out
 *                            portrait lower right, agent name / cell / email lower left.
 *
 * Fallbacks (multi-agency, no HFC strings): cover slogan → agency tagline; cover website →
 * agency website_url (scheme stripped for display); cover phone → agency phone. Every missing
 * piece degrades cleanly (no broken image, no empty band line).
 */
class ViewingPackCoverService
{
    use ViewingPackPdfSupport;

    public const STYLE_STANDARD = 'standard';
    public const STYLE_CLASSIC  = 'classic_welcome';

    /** @var array<string,string> key => label (the order shown on the settings page) */
    public const STYLES = [
        self::STYLE_STANDARD => 'Standard (CoreX default)',
        self::STYLE_CLASSIC  => 'Classic welcome',
    ];

    /** The platform's own default navy for the `default_color` role (Agency::branding()). */
    public const PLATFORM_NAVY = '#0b2a4a';
    /** Classic welcome's own navy (band, "WELCOME TO", "VIEWING DAY", agent details). */
    public const CLASSIC_NAVY = '#002060';
    /** Classic welcome's own accent ("YOUR"). */
    public const CLASSIC_ACCENT = '#C00000';
    /** Last-resort light blue when the agency has set none of its colour roles. */
    public const CLASSIC_LIGHT = '#00B4D8';

    /** Settings keys this feature owns (settings page, wizard, audit). */
    public const SETTING_KEYS = [
        'viewing_pack_cover_style',
        'viewing_pack_cover_slogan',
        'viewing_pack_cover_website',
        'viewing_pack_cover_phone',
        'viewing_pack_cover_accent_color',
    ];

    // ── Page geometry (A4 at 96 dpi, as dompdf renders it) ─────────────────────────────
    public const PAGE_W = 794;
    public const PAGE_H = 1120;   // a hair under the 1122.5px page box: never emits a blank trailing page
    public const BAND_W = 60;     // 7.5% of the page width

    public static function normaliseStyle(?string $style): string
    {
        return array_key_exists((string) $style, self::STYLES) ? (string) $style : self::STYLE_STANDARD;
    }

    /**
     * Everything the cover views need. $overrides lets the settings-page preview show the
     * values currently typed into the form before they are saved (keys = SETTING_KEYS; a
     * present key wins even when blank).
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public function dataFor(?Agency $agency, ?User $agent, array $overrides = []): array
    {
        $pick = function (string $key) use ($agency, $overrides) {
            return array_key_exists($key, $overrides) ? $overrides[$key] : $agency?->{$key};
        };

        $style = self::normaliseStyle($pick('viewing_pack_cover_style'));

        $slogan  = $this->clean($pick('viewing_pack_cover_slogan'), 120) ?? $this->clean($agency?->tagline, 120);
        $website = $this->displayWebsite($this->clean($pick('viewing_pack_cover_website'), 120) ?? $this->clean($agency?->website_url, 120));
        $phone   = $this->clean($pick('viewing_pack_cover_phone'), 40) ?? $this->clean($agency?->phone, 40);

        $logo = $this->imageBox($this->publicDataUri($agency?->logo_path), 646, 210);

        // Cut-out first (transparent PNG), then the plain profile photo, then nothing.
        $photoUri = null;
        if ($agent) {
            $cutout = method_exists($agent, 'profilePhotoCutoutUrl') ? $agent->profilePhotoCutoutUrl() : null;
            $photoUri = $this->publicDataUri($cutout);
            if ($photoUri === null && method_exists($agent, 'profilePhotoUrl')) {
                $photoUri = $this->publicDataUri($agent->profilePhotoUrl());
            }
        }

        return [
            'style'      => $style,
            'isClassic'  => $style === self::STYLE_CLASSIC,
            'slogan'     => $slogan,
            'website'    => $website,
            'phone'      => $phone,
            'navy'       => $this->primaryColor($agency),
            'accent'     => $this->hex($pick('viewing_pack_cover_accent_color')) ?? self::CLASSIC_ACCENT,
            'light'      => $this->lightColor($agency),
            'agencyName' => (string) ($agency?->name ?: ''),
            'logo'       => $logo,
            'photo'      => $this->imageBox($photoUri, 380, 376),
            'agentName'  => (string) ($agent?->name ?: ''),
            'agentCell'  => $agent ? (string) ($this->clean($agent->cell ?? null, 40) ?? $this->clean($agent->phone ?? null, 40) ?? '') : '',
            'agentEmail' => $agent ? (string) ($this->clean($agent->email ?? null, 120) ?? '') : '',
        ];
    }

    /**
     * Full view data for the cover template (the same keys ViewingPackBuyerPdfService
     * passes), filled with sample buyer text — used by the settings-page preview.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public function previewViewData(Agency $agency, ?User $agent, array $overrides = []): array
    {
        $cover = $this->dataFor($agency, $agent, $overrides);

        return [
            'buyerName'     => 'Sample Buyer',
            'propertyCount' => 3,
            'date'          => now()->format('j F Y'),
            'agencyName'    => $cover['agencyName'] ?: 'Your agency',
            'agentName'     => $cover['agentName'],
            'agentPhone'    => $cover['agentCell'],
            'agentEmail'    => $cover['agentEmail'],
            'logo'          => $cover['logo']['uri'] ?? null,
            'agentPhoto'    => $cover['photo']['uri'] ?? null,
            'cover'         => $cover,
            'preview'       => true,
        ];
    }

    /**
     * Audit: write one row when any cover setting actually changes. Called by the
     * company-settings save BEFORE the agency row is updated (so old values are read from
     * the model). Returns true when a row was written.
     *
     * @param  array<string,mixed>  $incoming  validated request data (only posted keys)
     */
    public function recordChange(Agency $agency, array $incoming, ?User $by): bool
    {
        $old = [];
        $new = [];
        foreach (self::SETTING_KEYS as $key) {
            if (! array_key_exists($key, $incoming)) {
                continue;
            }
            $before = $agency->{$key};
            $after  = $incoming[$key];
            if ((string) ($before ?? '') !== (string) ($after ?? '')) {
                $old[$key] = $before;
                $new[$key] = $after;
            }
        }

        if ($new === []) {
            return false;
        }

        ViewingPackCoverAuditEntry::create([
            'agency_id'          => $agency->id,
            'changed_by_user_id' => $by?->id,
            'old_values'         => $old,
            'new_values'         => $new,
            'changed_at'         => now(),
        ]);

        return true;
    }

    // ── colours ────────────────────────────────────────────────────────────────────────

    /**
     * The "cover primary" navy: the agency's own navy (its `default_color` role) when it has
     * set one, else the style's #002060. CoreX stores #0b2a4a as the platform default for
     * that role and the branding form re-posts it for every agency that saves, so a value
     * equal to the platform default is treated as "not set" — only a navy the agency chose
     * itself overrides the style's.
     */
    private function primaryColor(?Agency $agency): string
    {
        $own = $this->hex($agency?->default_color);
        if ($own !== null && strcasecmp($own, self::PLATFORM_NAVY) !== 0) {
            return $own;
        }

        return self::CLASSIC_NAVY;
    }

    /** The light-blue accent block: the agency's own icon / button colour. */
    private function lightColor(?Agency $agency): string
    {
        return $this->hex($agency?->icon_color) ?? $this->hex($agency?->button_color) ?? self::CLASSIC_LIGHT;
    }

    private function hex(mixed $value): ?string
    {
        $v = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtoupper($v) : null;
    }

    // ── text ───────────────────────────────────────────────────────────────────────────

    private function clean(mixed $value, int $max): ?string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($v === '') {
            return null;
        }

        return mb_substr($v, 0, $max);
    }

    /** "https://www.x.co.za/" → "www.x.co.za" (a typed "www.x.co.za" is left as typed). */
    private function displayWebsite(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $v = rtrim((string) preg_replace('#^https?://#i', '', $url), '/');

        return $v !== '' ? $v : null;
    }

    // ── images ─────────────────────────────────────────────────────────────────────────

    /**
     * Fit a data-URI image inside maxW×maxH keeping its aspect ratio. Explicit pixel sizes
     * (never CSS object-fit, which dompdf ignores) so the image is never stretched. Null when
     * there is no image or its size cannot be read — callers then omit it cleanly.
     *
     * @return array{uri:string,w:int,h:int}|null
     */
    private function imageBox(?string $dataUri, int $maxW, int $maxH): ?array
    {
        if (! $dataUri || ! str_contains($dataUri, ';base64,')) {
            return null;
        }

        $bytes = base64_decode(substr($dataUri, strpos($dataUri, ';base64,') + 8), true);
        $size  = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        if (! $size || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        $scale = min($maxW / $size[0], $maxH / $size[1]);

        return ['uri' => $dataUri, 'w' => max(1, (int) round($size[0] * $scale)), 'h' => max(1, (int) round($size[1] * $scale))];
    }
}
