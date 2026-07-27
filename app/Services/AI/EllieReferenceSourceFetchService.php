<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\SsrfBlockedException;
use App\Models\AI\EllieReferenceChunk;
use App\Models\AI\EllieReferenceSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ONLY code path in CoreX that ever fetches an Ellie reference source over
 * the network. Called exclusively by an admin action (add / "Refresh now") or
 * the daily `ellie:refresh-reference-sources` cron — never from a chat request.
 * Ellie's own tool (EllieToolkit::searchReferenceSites) only ever SELECTs
 * already-indexed rows; there is no tool that can reach this class.
 *
 * That separation is the actual safety mechanism. Everything below (SSRF guards)
 * is defence in depth for the admin-triggered fetch itself — it stops an admin's
 * pasted URL (or a redirect it points at) from being used to probe CoreX's own
 * internal network, not from a chat-driven request that structurally cannot
 * happen.
 *
 * Spec: .ai/specs/ellie-reference-sources.md §6.
 */
class EllieReferenceSourceFetchService
{
    private const MAX_REDIRECTS = 5;
    private const TIMEOUT_SECONDS = 8;
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB
    private const MIN_CHUNK_CHARS = 400;
    private const MAX_CHUNK_CHARS = 3000;

    private const ALLOWED_CONTENT_TYPES = ['text/html', 'text/plain', 'application/xhtml+xml'];

    public function __construct(
        private readonly EmbeddingService $embeddings,
    ) {
    }

    /**
     * Validate a URL is even eligible to become a source, WITHOUT fetching it.
     * Used at add-time so a source that can never fetch is never created.
     *
     * @return string|null An error message, or null if the URL passes.
     */
    public function validateAddable(string $url): ?string
    {
        try {
            $target = $this->guardUrl($url);
            // Resolving here too — not just scheme/host shape — is what makes
            // this check mean anything: a literal private/loopback/reserved IP
            // (or a hostname that resolves to one) must be refused before a
            // source row exists at all, not discovered later when the first
            // fetch quietly fails.
            $this->resolveSafePublicIp($target['host']);
        } catch (SsrfBlockedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Run the full guarded fetch → extract → chunk → embed pipeline for one
     * source, and persist the result. Never throws — failures are recorded on
     * the source row itself (BUILD_STANDARD §4: a broken fetch degrades this
     * one source, it does not take down the refresh run).
     */
    public function refresh(EllieReferenceSource $source): void
    {
        try {
            $fetched = $this->fetchGuarded($source->url);
        } catch (SsrfBlockedException $e) {
            $this->markError($source, $e->getMessage());

            return;
        } catch (Throwable $e) {
            Log::warning('ELLIE_REFERENCE_FETCH_FAILED', ['source_id' => $source->id, 'error' => $e->getMessage()]);
            $this->markError($source, 'Fetch failed: ' . $e->getMessage());

            return;
        }

        $text = $this->extractText($fetched['body']);
        if (trim($text) === '') {
            $this->markError($source, 'Page fetched successfully but no readable text content was found.');

            return;
        }

        $hash = hash('sha256', $text);

        // Content unchanged since last successful fetch — touch the timestamp,
        // skip re-chunking and re-embedding entirely.
        if ($source->content_hash !== null && $source->content_hash === $hash) {
            $source->forceFill([
                'last_fetched_at' => now(),
                'last_fetch_status' => EllieReferenceSource::STATUS_OK,
                'fetch_error' => null,
            ])->save();

            return;
        }

        $chunks = $this->chunkText($text);
        if (empty($chunks)) {
            $this->markError($source, 'Page fetched successfully but produced no usable chunks.');

            return;
        }

        $vectors = $this->embeddings->embedBatch($chunks, EmbeddingService::KIND_PASSAGE);

        DB::transaction(function () use ($source, $chunks, $vectors, $hash, $fetched) {
            // Replace this source's chunks atomically — a fetch that produced
            // different content should not leave a mix of old and new chunks
            // searchable mid-write.
            $source->chunks()->delete();

            foreach ($chunks as $i => $content) {
                $vector = $vectors[$i] ?? null;

                EllieReferenceChunk::create([
                    'source_id' => $source->id,
                    'chunk_index' => $i,
                    'content' => $content,
                    'embedding' => $vector,
                    'has_embedding' => $vector !== null,
                ]);
            }

            $source->forceFill([
                'title' => $source->title ?: $this->extractTitle($fetched['body']),
                'content_hash' => $hash,
                'last_fetched_at' => now(),
                'last_fetch_status' => EllieReferenceSource::STATUS_OK,
                'fetch_error' => null,
            ])->save();
        });
    }

    private function markError(EllieReferenceSource $source, string $message): void
    {
        // Deliberately does NOT touch existing chunks. A page that is
        // temporarily down must not make Ellie forget what she last
        // successfully read — see .ai/specs/ellie-reference-sources.md §6.
        $source->forceFill([
            'last_fetched_at' => now(),
            'last_fetch_status' => EllieReferenceSource::STATUS_ERROR,
            'fetch_error' => mb_substr($message, 0, 2000),
        ])->save();
    }

    // ── The guarded fetch ────────────────────────────────────────────────────

    /**
     * @return array{body: string, final_url: string}
     */
    private function fetchGuarded(string $url): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->guardUrl($current);
            $ip = $this->resolveSafePublicIp($target['host']);

            $response = $this->requestPinnedToIp($target, $ip);

            $status = $response['status'];

            if ($status >= 300 && $status < 400 && isset($response['headers']['location'])) {
                if ($hop === self::MAX_REDIRECTS) {
                    throw new SsrfBlockedException('Too many redirects.');
                }

                $current = $this->resolveRedirectTarget($current, $response['headers']['location']);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new SsrfBlockedException("Fetch failed with HTTP {$status}.");
            }

            $contentType = strtolower(explode(';', $response['headers']['content-type'] ?? '')[0] ?? '');
            if ($contentType !== '' && ! in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)) {
                throw new SsrfBlockedException("Refused content type: {$contentType}.");
            }

            if (mb_strlen($response['body'], '8bit') > self::MAX_BYTES) {
                throw new SsrfBlockedException('Response exceeded the ' . (self::MAX_BYTES / 1024 / 1024) . 'MB size cap.');
            }

            return ['body' => $response['body'], 'final_url' => $current];
        }

        throw new SsrfBlockedException('Too many redirects.');
    }

    /**
     * Parse + validate a URL's scheme and host shape. Does NOT resolve DNS —
     * that happens separately, immediately before each connection, so a hostname
     * that was safe a moment ago (or at add-time) is re-checked every time
     * (blocks DNS rebinding).
     *
     * @return array{scheme: string, host: string, port: int, path: string}
     */
    protected function guardUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new SsrfBlockedException('Not a valid URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfBlockedException("Refused scheme '{$scheme}' — only http/https are allowed.");
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'path' => ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''),
        ];
    }

    /**
     * Resolve a host to a public IP address, rejecting private, loopback,
     * link-local and reserved ranges (RFC1918, 127.0.0.0/8, 169.254.0.0/16,
     * ::1, fc00::/7, and friends) — for both a literal IP host and a hostname
     * that resolves to one.
     *
     * PHP's FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE cover exactly
     * this set for both IPv4 and IPv6, so hand-rolled CIDR math is unnecessary
     * and more error-prone than the built-in filter.
     *
     * protected (not private) so tests can override DNS resolution with a
     * Mockery partial mock instead of depending on real, network-reachable DNS
     * inside the test run — the guard logic being tested here is the IP-range
     * check and the per-hop re-validation, not whether PHP's resolver works.
     */
    protected function resolveSafePublicIp(string $host): string
    {
        $literal = trim($host, '[]');
        $candidates = filter_var($literal, FILTER_VALIDATE_IP) ? [$literal] : $this->resolveHostIps($host);

        if (empty($candidates)) {
            throw new SsrfBlockedException("Could not resolve host '{$host}'.");
        }

        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        throw new SsrfBlockedException("Host '{$host}' resolves only to a private, loopback or reserved address — refused.");
    }

    /**
     * @return array<int, string>
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Perform the HTTP request, pinned to the already-validated IP via curl's
     * CURLOPT_RESOLVE. This is what actually closes the DNS-rebinding gap: the
     * connection is made to the IP we checked, not to whatever the host resolves
     * to a second time at connect. The Host header still carries the original
     * hostname, so virtual-hosted sites behave normally.
     *
     * Redirects are NOT followed automatically — each hop is re-validated by
     * the caller (fetchGuarded) so a redirect to a private target is refused
     * rather than silently followed.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function requestPinnedToIp(array $target, string $ip): array
    {
        // CURLOPT_RESOLVE always needs an explicit port, but the request URL
        // itself must NOT carry one when it's just the scheme default — an
        // explicit ":443"/":80" produces a non-standard Host header some
        // servers mishandle, and it's needless noise otherwise.
        $resolve = "{$target['host']}:{$target['port']}:{$ip}";
        $isDefaultPort = ($target['scheme'] === 'https' && $target['port'] === 443)
            || ($target['scheme'] === 'http' && $target['port'] === 80);
        $authority = $isDefaultPort ? $target['host'] : "{$target['host']}:{$target['port']}";

        $response = Http::withOptions([
                'allow_redirects' => false,
                'curl' => [
                    CURLOPT_RESOLVE => [$resolve],
                    // Belt-and-braces size cap — only takes effect when the
                    // server declares Content-Length; the explicit strlen()
                    // check in fetchGuarded() is what catches a server that
                    // doesn't.
                    CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                ],
            ])
            ->withHeaders(['User-Agent' => 'CoreXOS-EllieReferenceFetcher/1.0'])
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(5)
            ->get("{$target['scheme']}://{$authority}{$target['path']}");

        $headers = [];
        foreach ($response->headers() as $key => $values) {
            $headers[strtolower($key)] = is_array($values) ? ($values[0] ?? '') : (string) $values;
        }

        return [
            'status' => $response->status(),
            'headers' => $headers,
            'body' => $response->body(),
        ];
    }

    private function resolveRedirectTarget(string $currentUrl, string $location): string
    {
        // Location may be relative — resolve it against the current URL.
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($currentUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }

        $path = $base['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') ?: 0) ?: '';

        return "{$scheme}://{$host}{$port}{$dir}/{$location}";
    }

    // ── Text extraction & chunking ──────────────────────────────────────────

    private function extractText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//script|//style|//nav|//footer|//noscript|//svg') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        $text = $body ? $body->textContent : $doc->textContent;

        // Collapse whitespace — extracted DOM text is full of newlines/indentation.
        $text = preg_replace('/[ \t]+/', ' ', (string) $text) ?? '';
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text) ?? '';

        return trim($text);
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $title = trim(html_entity_decode(strip_tags($m[1])));

            return $title !== '' ? mb_substr($title, 0, 255) : null;
        }

        return null;
    }

    /**
     * Simple paragraph-boundary chunking, sized for reference pages (which run
     * far shorter than the uploaded documents DocumentProcessingService::chunkText()
     * was built for). Paragraphs are merged until MIN_CHUNK_CHARS, and a
     * paragraph longer than MAX_CHUNK_CHARS is hard-split.
     *
     * @return array<int, string>
     */
    private function chunkText(string $text): array
    {
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/', $text) ?: []),
            fn ($p) => $p !== ''
        ));

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $para) {
            if (mb_strlen($para) > self::MAX_CHUNK_CHARS) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
                foreach (mb_str_split($para, self::MAX_CHUNK_CHARS) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            $candidate = $buffer === '' ? $para : $buffer . "\n\n" . $para;

            if (mb_strlen($candidate) > self::MAX_CHUNK_CHARS && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = $para;

                continue;
            }

            $buffer = $candidate;

            if (mb_strlen($buffer) >= self::MIN_CHUNK_CHARS) {
                $chunks[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}
