<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AT-22 item 2/7 — recover a listing's image URL from its portal listing page.
 *
 * THE PROBLEM this solves: 4032 prospecting_listings rows carry a
 * thumbnail_path but ZERO have a thumbnail_source_url (the column was added
 * 2026-06-11 and never backfilled), and every stored file was orphaned by the
 * Laravel 11 `local`-disk-root move. So `prospecting:rehydrate-thumbnails`
 * — which can only re-fetch when source_url is known — is a no-op on all of
 * them, leaving most competitor cards showing "No photo".
 *
 * THE RECOVERY: 100% of those rows DO have a portal_url (Property24 /
 * PrivateProperty listing page). Both portals expose the primary listing image
 * as an Open Graph `og:image` meta tag — `images.prop24.com/…` and
 * `images.pp.co.za/listing/…` respectively. Fetching the listing page and
 * reading og:image gives us a fresh, re-downloadable image URL WITHOUT a new
 * portal capture — the same path used to retrieve PP-T5391969 during the item-2
 * branding investigation. The recovered URL is persisted to thumbnail_source_url
 * so the row is rehydratable from then on, and the download flows through
 * DownloadListingThumbnail → ListingImageValidator content gate, so a recovered
 * image that turns out to be a competitor brand card is blocked (item 2).
 *
 * Conservative on every failure: a delisted/410 page, a missing og:image, a
 * timeout, or a non-http(s) value all return null — the caller counts it as
 * unresolved and moves on; nothing is fabricated.
 */
class PortalImageUrlResolver
{
    /** Browser-like UA — portals 403 obvious bots. */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    public function __construct(
        public int $timeoutSeconds = 20,
    ) {}

    /**
     * Resolve the og:image URL for a listing by fetching its portal_url.
     * Returns null when the page can't be fetched or carries no usable image.
     */
    public function resolveForListing(ProspectingListing $listing): ?string
    {
        $portalUrl = trim((string) ($listing->portal_url ?? ''));
        if ($portalUrl === '' || ! preg_match('#^https?://#i', $portalUrl)) {
            return null;
        }

        $html = $this->fetch($portalUrl);
        if ($html === null) {
            return null;
        }

        $image = $this->extractOgImage($html);
        if ($image === null || ! preg_match('#^https?://#i', $image)) {
            return null;
        }

        return $image;
    }

    /**
     * Pure parse — extract the og:image content URL from page HTML, tolerant of
     * attribute order (`property` before or after `content`) and quote style.
     * Returns null when no og:image is present. Network-free / unit-testable.
     */
    public function extractOgImage(string $html): ?string
    {
        // property=… then content=…
        if (preg_match('/<meta[^>]+property=["\']og:image(?::url)?["\'][^>]*>/i', $html, $tag)
            && preg_match('/content=["\']([^"\']+)["\']/i', $tag[0], $m)) {
            return $this->clean($m[1]);
        }

        // content=… then property=… (reversed attribute order)
        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:image(?::url)?["\']/i', $html, $m)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    private function clean(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        return $url === '' ? null : $url;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'text/html,application/xhtml+xml',
            ])
                ->timeout($this->timeoutSeconds)
                ->retry(1, 250, throw: false)
                ->get($url);

            if (! $response->ok()) {
                return null;
            }

            $body = $response->body();
            return $body === '' ? null : $body;
        } catch (\Throwable $e) {
            Log::warning("PortalImageUrlResolver: fetch failed for {$url} — {$e->getMessage()}");
            return null;
        }
    }
}
