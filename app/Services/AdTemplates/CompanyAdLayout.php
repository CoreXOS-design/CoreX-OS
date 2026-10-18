<?php

namespace App\Services\AdTemplates;

use App\Models\Agency;
use App\Models\CompanyAd;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The rules that make a layout a COMPANY ad (.ai/specs/company-ads.md §6).
 *
 * One definition used by the Ad Builder's company mode (which elements the palette offers) and by the
 * server on every save (what is allowed to be stored):
 *  - only brand / agent / decorative elements — never a listing element (price, address, photos 1-5, facts, QR ...);
 *  - a Custom Image may only show a picture from the built-in library or one this agency uploaded for company ads;
 *  - no property-type variants (they only make sense for listings).
 */
class CompanyAdLayout
{
    /** Element types a company ad may hold. Everything else in the kernel's FIELDS list is listing-shaped. */
    public const ALLOWED_FIELDS = [
        'custom_image',
        'agent_name', 'agent_email', 'agent_phone', 'agent_designation', 'agent_avatar',
        'logo', 'agency_logo', 'agency_name', 'website', 'agency_phone', 'watermark',
        'custom_text', 'badge', 'line', 'shape', 'color_block', 'gradient', 'ribbon',
    ];

    /** Canvas sizes, same as CANVAS_PRESETS in the render kernel. */
    public const CANVAS_SIZES = [
        'facebook'  => [1200, 628],
        'instagram' => [1080, 1080],
        'story'     => [1080, 1920],
        'whatsapp'  => [900, 900],
    ];

    /**
     * Clean a layout from the browser before it is stored. Returns [layout, canvasKey].
     * Never trusts the browser: disallowed elements are removed, foreign picture addresses are cleared.
     */
    public static function sanitize(array $layout, int $agencyId): array
    {
        $elements = [];
        foreach ((array) ($layout['elements'] ?? []) as $el) {
            if (! is_array($el) || ! in_array($el['field'] ?? null, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            if (($el['field'] ?? null) === 'custom_image') {
                $el['src'] = self::allowedPictureSrc((string) ($el['src'] ?? ''), $agencyId) ?? '';
                unset($el['mediaKind']);
            }
            $elements[] = $el;
        }

        $preset = (string) ($layout['canvasPreset'] ?? '');
        if (! isset(self::CANVAS_SIZES[$preset])) {
            $preset = self::presetFor((int) ($layout['canvasW'] ?? 0), (int) ($layout['canvasH'] ?? 0)) ?? 'facebook';
        }
        [$w, $h] = self::CANVAS_SIZES[$preset];

        $clean = [
            'canvasW'       => $w,
            'canvasH'       => $h,
            'canvasPreset'  => $preset,
            'canvasBg'      => $layout['canvasBg']      ?? null,
            'canvasBgMode'  => $layout['canvasBgMode']  ?? null,
            'canvasBgFrom'  => $layout['canvasBgFrom']  ?? null,
            'canvasBgTo'    => $layout['canvasBgTo']    ?? null,
            'canvasBgAngle' => $layout['canvasBgAngle'] ?? null,
            'elements'      => $elements,
        ];

        return [$clean, $preset];
    }

    /**
     * A picture address a company ad may show: a built-in library picture, or one of THIS agency's company-ad
     * uploads. Anything else (another agency's file, an outside address, a property photo) is refused.
     */
    public static function allowedPictureSrc(string $src, int $agencyId): ?string
    {
        $path = parse_url($src, PHP_URL_PATH) ?: '';
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        if (CompanyAdLibrary::isLibraryPath($path)) {
            return $path;
        }
        $prefix = '/storage/company-ads/' . $agencyId . '/';
        if (str_starts_with($path, $prefix) && Storage::disk('public')->exists(substr($path, strlen('/storage/')))) {
            return $path;
        }

        return null;
    }

    public static function presetFor(int $w, int $h): ?string
    {
        foreach (self::CANVAS_SIZES as $key => [$cw, $ch]) {
            if ($cw === $w && $ch === $h) {
                return $key;
            }
        }

        return null;
    }

    /**
     * What the render kernel draws a company ad with: the agency's own brand and the signed-in user as the
     * agent. Nothing listing-shaped — a company ad has no listing. Always the requesting agency (never HFC's).
     */
    public static function brandData(User $user, ?Agency $agency): array
    {
        $name     = (string) ($agency?->name ?? '');
        $logoPath = $agency?->logo_path;

        return [
            'agency_name'       => $name,
            'website'           => (string) ($agency?->website_url ?? ''),
            'agency_phone'      => trim((string) ($agency?->phone ?? '')),
            'logo'              => $logoPath ? Property::adSafeImageUrl(asset('storage/' . $logoPath)) : null,
            'watermark'         => strtoupper($name),
            'agent_name'        => strtoupper((string) $user->name),
            'agent_email'       => (string) $user->email,
            'agent_phone'       => (string) ($user->cell ?: $user->phone ?: ''),
            'agent_designation' => (string) ($user->designation ?: ''),
            'agent_avatar'      => Property::adSafeImageUrl($user->profilePhotoUrl()),
        ];
    }

    public static function canvasLabel(CompanyAd $ad): string
    {
        return match ($ad->canvas) {
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'story'     => 'Story',
            'whatsapp'  => 'WhatsApp',
            default     => '—',
        };
    }
}
