<?php

namespace App\Services\AdStudio;

use App\Models\AdStudioSetting;

/**
 * AT-527 - CoreX's brand for the video ads: config defaults (config/ad-studio.php) overlaid with what the System Owner saved in Video ads -> Brand settings.
 * Platform level, not per agency (spec .ai/specs/training-videos.md 9.21.5).
 */
final class AdBrand
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return (array) config('ad-studio.brand', []);
    }

    /** @return array<string,mixed> the brand in force: defaults, then the owner's saved values. A saved blank social stays blank. */
    public static function current(): array
    {
        $brand = self::defaults();
        $saved = AdStudioSetting::allValues();
        foreach (['logo', 'website', 'email', 'phone', 'join_line', 'cta_line', 'follow_line', 'tagline', 'music'] as $k) {
            if (array_key_exists($k, $saved)) {
                $brand[$k] = $saved[$k];
            }
        }
        foreach (['colours', 'social'] as $k) {
            if (isset($saved[$k]) && is_array($saved[$k])) {
                $brand[$k] = array_merge((array) ($brand[$k] ?? []), $saved[$k]);
            }
        }

        return $brand;
    }

    /** Absolute path of the logo file (a public/ asset, or an uploaded file on the public disk), or null when it is missing. */
    public static function logoPath(array $brand): ?string
    {
        $logo = (string) ($brand['logo'] ?? '');
        if ($logo === '') {
            return null;
        }
        foreach ([public_path($logo), storage_path('app/public/' . ltrim($logo, '/'))] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /** A URL the owner's browser can show the logo from. */
    public static function logoUrl(array $brand): ?string
    {
        $logo = (string) ($brand['logo'] ?? '');
        if ($logo === '') {
            return null;
        }
        if (is_file(public_path($logo))) {
            return asset($logo);
        }

        return is_file(storage_path('app/public/' . ltrim($logo, '/'))) ? asset('storage/' . ltrim($logo, '/')) : null;
    }

    /** Social handles that are filled in, label => value. @return array<string,string> */
    public static function filledSocials(array $brand): array
    {
        $out = [];
        foreach ((array) config('ad-studio.social_labels', []) as $key => $label) {
            $v = trim((string) ($brand['social'][$key] ?? ''));
            if ($v !== '') {
                $out[$label] = $v;
            }
        }

        return $out;
    }
}
