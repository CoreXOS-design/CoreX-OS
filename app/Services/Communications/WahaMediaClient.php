<?php

namespace App\Services\Communications;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AT-148 — downloads media (voice notes) from the WAHA server session.
 *
 * The WAHA GOWS session stores the DECRYPTED media itself and hands CoreX a
 * downloadable URL in the message webhook (media.url). There is NO separate
 * "download-media" RPC for the GOWS engine — we authenticate with the WAHA API
 * key and GET that URL; the body is the cleartext .ogg/.opus bytes.
 *
 * Guards (a compliance archive fetching from an internal service must not become
 * an SSRF pivot or a volume-filler):
 *   - host allow-list  — only WAHA's own host(s) may be fetched.
 *   - timeout          — a single media fetch is time-bounded.
 *   - max_media_bytes  — a hard ceiling; Content-Length is rejected early when
 *                        present, and the actual body is re-checked.
 *
 * Any failure throws — the caller (WaArchiveIngestor) catches it and archives
 * the attachment as media-pending, so a bodyless media message is never dropped.
 */
class WahaMediaClient
{
    /**
     * @return array{bytes:string, mime:?string, size:int}
     * @throws RuntimeException on any download failure
     */
    public function download(string $url): array
    {
        $url = $this->normalizeUrl(trim($url));
        $this->assertHostAllowed($url);

        $timeout = max(1, (int) config('communications.waha.download_timeout_seconds', 30));
        $maxBytes = max(1, (int) config('communications.waha.max_media_bytes', 50 * 1024 * 1024));

        $request = Http::timeout($timeout)->connectTimeout($timeout);
        if ($apiKey = config('communications.waha.api_key')) {
            $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
        }

        try {
            $response = $request->get($url);
        } catch (\Throwable $e) {
            throw new RuntimeException('WAHA media fetch failed: ' . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('WAHA media fetch returned HTTP ' . $response->status());
        }

        // Reject early if the server advertises an oversize body.
        $declared = (int) $response->header('Content-Length');
        if ($declared > 0 && $declared > $maxBytes) {
            throw new RuntimeException("WAHA media exceeds cap ({$declared} > {$maxBytes} bytes)");
        }

        $bytes = $response->body();
        $size = strlen($bytes);
        if ($size === 0) {
            throw new RuntimeException('WAHA media fetch returned an empty body');
        }
        if ($size > $maxBytes) {
            throw new RuntimeException("WAHA media exceeds cap ({$size} > {$maxBytes} bytes)");
        }

        // Content-Type minus any ;charset — WAHA sends e.g. "audio/ogg; codecs=opus".
        $mime = $response->header('Content-Type') ?: null;

        return ['bytes' => $bytes, 'mime' => $mime, 'size' => $size];
    }

    /** Resolve a relative media path against the configured WAHA base URL. */
    private function normalizeUrl(string $url): string
    {
        if ($url === '') {
            throw new RuntimeException('Empty WAHA media URL');
        }
        if (! preg_match('#^https?://#i', $url)) {
            $base = (string) config('communications.waha.base_url', '');
            $url = $base . '/' . ltrim($url, '/');
        }

        return $url;
    }

    /**
     * SSRF guard: only ever fetch from WAHA's own host(s). Defends against a
     * spoofed media.url pointing the archive server at an internal service.
     */
    private function assertHostAllowed(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            throw new RuntimeException('WAHA media URL has no host');
        }

        $allowed = (array) config('communications.waha.allowed_media_hosts', []);
        // Always trust the configured base_url host.
        if ($baseHost = parse_url((string) config('communications.waha.base_url', ''), PHP_URL_HOST)) {
            $allowed[] = $baseHost;
        }
        $allowed = array_values(array_unique(array_filter(array_map('strtolower', $allowed))));

        if (! in_array(strtolower($host), $allowed, true)) {
            throw new RuntimeException("WAHA media host not allowed: {$host}");
        }
    }
}
