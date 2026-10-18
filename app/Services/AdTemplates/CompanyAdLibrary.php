<?php

namespace App\Services\AdTemplates;

/**
 * The built-in library of seasonal pictures for company ads (.ai/specs/company-ads.md §4).
 * Global and read-only; defined in config/company-ad-library.php, files under public/images/company-ad-library/.
 * Every picture is public domain / CC0 (commercial use, no attribution) — sources in SOURCES.md beside them.
 */
class CompanyAdLibrary
{
    public const URL_PREFIX = '/images/company-ad-library/';

    /** @return array<string, string> occasion key => label, in calendar order. */
    public static function occasions(): array
    {
        $out = [];
        foreach ((array) config('company-ad-library.occasions', []) as $key => $o) {
            $out[$key] = (string) ($o['label'] ?? $key);
        }

        return $out;
    }

    public static function isOccasion(?string $key): bool
    {
        return $key !== null && array_key_exists($key, (array) config('company-ad-library.occasions', []));
    }

    public static function label(?string $key): string
    {
        return self::isOccasion($key) ? self::occasions()[$key] : 'Other';
    }

    /**
     * Every picture, grouped by occasion, for the builder's picker.
     * @return array<int, array{occasion: string, label: string, pictures: array<int, array{url: string, alt: string}>}>
     */
    public static function grouped(): array
    {
        $out = [];
        foreach ((array) config('company-ad-library.occasions', []) as $key => $o) {
            $pics = [];
            foreach ((array) ($o['pictures'] ?? []) as $p) {
                $url = self::URL_PREFIX . ltrim((string) $p['file'], '/');
                if (is_file(public_path(ltrim($url, '/')))) {
                    $pics[] = ['url' => $url, 'alt' => (string) ($p['alt'] ?? '')];
                }
            }
            if ($pics) {
                $out[] = ['occasion' => $key, 'label' => (string) ($o['label'] ?? $key), 'pictures' => $pics];
            }
        }

        return $out;
    }

    /** Is this host-relative path exactly one of the library's own pictures? */
    public static function isLibraryPath(string $path): bool
    {
        if (! str_starts_with($path, self::URL_PREFIX)) {
            return false;
        }
        $file = substr($path, strlen(self::URL_PREFIX));
        foreach ((array) config('company-ad-library.occasions', []) as $o) {
            foreach ((array) ($o['pictures'] ?? []) as $p) {
                if (ltrim((string) $p['file'], '/') === $file) {
                    return is_file(public_path(ltrim($path, '/')));
                }
            }
        }

        return false;
    }
}
