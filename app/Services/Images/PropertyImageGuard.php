<?php

namespace App\Services\Images;

use App\Models\Property;
use Illuminate\Support\Facades\Storage;

/**
 * The single authority on whether a property-image reference is legitimate.
 *
 * CoreX stores gallery photos as public URLs inside JSON columns
 * (`gallery_images_json`, `gallery_categories_json`, the time-of-day sets).
 * Nothing in the database constrains those strings to files that actually
 * exist — so every writer must check, and every writer must check the SAME way.
 * This class is that way.
 *
 * The invariant it defends:
 *
 *     A property's image JSON references only files that exist on disk
 *     inside that property's own directory (or a genuinely external URL).
 *
 * It was broken on 2026-07-06 (property 6060): a gallery photo was rotated —
 * which writes a new file and unlinks the original — and a stale browser tab
 * then re-posted its pre-rotation image array, resurrecting the URL of the
 * deleted file. Nothing validated the incoming array, so the dangling
 * reference persisted. PrivateProperty fetches photos BY URL and rejects the
 * whole `UpdateListing` call when one 404s, so a single dead reference blocked
 * every subsequent listing update to the portal (PP120, four failures before
 * anyone noticed). Property24 embeds bytes and skips missing files, so it hid
 * the fault entirely.
 *
 * Note on "external": images pulled from a portal (P24 mirrors, feed imports)
 * legitimately live on someone else's host. Those are NOT ours to verify — we
 * cannot stat them and must not drop them. Only CoreX-hosted `/storage/...`
 * URLs are subject to the existence check.
 */
class PropertyImageGuard
{
    /**
     * A CoreX-hosted image URL → its path relative to the `public` disk.
     * Returns null for anything we do not own: externally-hosted URLs, non-
     * storage paths, and traversal attempts.
     *
     * Accepts both the relative form actually stored (`/storage/properties/6060/a.jpg`)
     * and the absolute form some callers hand us (`https://corexos.co.za/storage/...`).
     */
    public static function relativePath(?string $url): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && $host !== '' && !in_array(strtolower($host), self::localHosts(), true)) {
            return null; // someone else's host — not ours to police
        }

        $path = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
        if (!str_starts_with($path, 'storage/')) {
            return null;
        }

        // Decode BEFORE the traversal check — "%2e%2e/" must not slip through.
        $rel = rawurldecode(substr($path, strlen('storage/')));

        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }

        return $rel;
    }

    /**
     * Every hostname that serves our own `/storage` — the canonical domain, the
     * alternate vhosts and the bare IP. Gallery URLs were historically written
     * in whichever form the creating request produced, so all of them appear in
     * the data and all of them are files we can stat. Keying this off APP_URL
     * alone would silently exempt the alternate-host references from validation.
     *
     * @return list<string> lowercased hosts
     */
    private static function localHosts(): array
    {
        $configured = (array) config('corex-images.local_hosts', []);
        $configured[] = parse_url((string) config('app.url'), PHP_URL_HOST);

        return array_values(array_unique(array_filter(array_map(
            fn ($h) => is_string($h) ? strtolower(trim($h)) : null,
            $configured
        ))));
    }

    /** True when the URL points at a file CoreX hosts (whether or not it exists). */
    public static function isLocal(?string $url): bool
    {
        return self::relativePath($url) !== null;
    }

    /** True when the URL is hosted elsewhere (portal mirror, feed import). */
    public static function isExternal(?string $url): bool
    {
        return is_string($url) && trim($url) !== '' && !self::isLocal($url);
    }

    /** True when a CoreX-hosted URL resolves to a file that is actually on disk. */
    public static function existsOnDisk(?string $url): bool
    {
        $rel = self::relativePath($url);

        return $rel !== null && Storage::disk('public')->exists($rel);
    }

    /** True when the URL lives inside this property's own directory. */
    public static function belongsToProperty(Property $property, ?string $url): bool
    {
        $rel = self::relativePath($url);

        return $rel !== null && str_starts_with($rel, "properties/{$property->id}/");
    }

    /**
     * Is this reference safe to persist against this property?
     *
     * External URLs pass (we cannot verify them and they are legitimate portal
     * mirrors). CoreX-hosted URLs must live in this property's directory AND
     * exist on disk — that combination blocks both the dangling reference that
     * broke PP syndication and cross-property/cross-agency file access.
     */
    public static function isPersistable(Property $property, ?string $url): bool
    {
        if (!is_string($url) || trim($url) === '') {
            return false;
        }

        if (self::isExternal($url)) {
            return true;
        }

        return self::belongsToProperty($property, $url) && self::existsOnDisk($url);
    }

    /**
     * Keep only the references that are safe to persist, preserving order and
     * discarding duplicates. Returns [kept, dropped] so the caller can log what
     * it refused — silent truncation reads as "we saved everything" when it didn't.
     *
     * @param  iterable<mixed>  $urls
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function partition(Property $property, iterable $urls): array
    {
        $kept = [];
        $dropped = [];

        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            if (self::isPersistable($property, $url)) {
                if (!in_array($url, $kept, true)) {
                    $kept[] = $url;
                }
            } else {
                $dropped[] = $url;
            }
        }

        return [$kept, $dropped];
    }
}
