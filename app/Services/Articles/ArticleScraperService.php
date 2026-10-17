<?php

namespace App\Services\Articles;

use App\Models\ArticlePool;
use App\Models\ArticleSource;
use App\Support\Net\SafeFeedUrl;
use App\Support\SuburbMapper;
use App\Support\Text\PlainText;
use Psr\Http\Message\StreamInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ArticleScraperService
{
    /** Longest slice of a feed's full-text (content:encoded) kept for the Article Library's brief. */
    private const CONTENT_MAX_CHARS = 3000;

    /** A feed bigger than this is refused (read stops at the cap), whatever the server claims. */
    public const MAX_FEED_BYTES = 2 * 1024 * 1024;

    /** Redirects followed by hand, each hop re-checked by SafeFeedUrl. */
    public const MAX_REDIRECTS = 3;

    private const FETCH_DEADLINE_SECS = 30;

    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    private const SUBURBS = [
        'Uvongo', 'Manaba Beach', 'Margate', 'Ramsgate', 'Shelly Beach',
        'Port Shepstone', 'Southbroom', 'San Lameer', 'Munster',
        'Hibberdene', 'Pennington', 'Scottburgh', 'Park Rynie',
        'Leisure Bay', 'Port Edward', 'Marina Beach', 'Trafalgar',
    ];

    private const REGIONS = [
        'South Coast', 'KZN', 'KwaZulu-Natal', 'Hibiscus Coast',
        'Ugu', 'Ray Nkonyeni', 'Lower South Coast',
    ];

    private const PROPERTY_TYPES = [
        'flat', 'apartment', 'house', 'townhouse',
        'sectional title', 'freehold', 'vacant land', 'commercial',
    ];

    private const TOPICS = [
        'interest rate', 'repo rate', 'prime rate', 'bond', 'mortgage',
        'transfer duty', 'property market', 'capital gains', 'rates and taxes',
    ];

    /**
     * Scrape every fetchable article source (the `article_sources` table — AT-456 Part C; the five
     * feeds that used to be hard-coded here are seeded into it) and return summary stats.
     *
     * Archived and paused sources are skipped.
     *
     * @return array{feeds_attempted: int, feeds_succeeded: int, articles_upserted: int, errors: array}
     */
    public function scrapeAll(): array
    {
        $stats = [
            'feeds_attempted'   => 0,
            'feeds_succeeded'   => 0,
            'articles_upserted' => 0,
            'errors'            => [],
        ];

        foreach (ArticleSource::query()->fetchable()->orderBy('id')->get() as $source) {
            $stats['feeds_attempted']++;

            try {
                $stats['articles_upserted'] += count($this->fetchSource($source));
                $stats['feeds_succeeded']++;
            } catch (\Throwable $e) {
                $msg = "[{$source->name}] {$e->getMessage()}";
                $stats['errors'][] = $msg;
                Log::warning("ArticleScraperService: {$msg}");
            }
        }

        return $stats;
    }

    /**
     * Fetch one source, upsert its items into article_pool (the staging store the presentations
     * also read) and record the fetch outcome on the source row.
     *
     * Returns the items found, newest first, each with the extra fields the Article Library needs
     * and article_pool does not keep: a longer plain-text `content` where the feed supplies full
     * text. Nothing is fetched beyond the feed itself — never the article's own page.
     *
     * @return list<array{hash: string, title: string, url: string, snippet: string, content: string, published_at: ?\DateTimeImmutable, source: string}>
     *
     * @throws \Throwable the fetch/parse error, after it has been recorded on the source.
     */
    public function fetchSource(ArticleSource $source): array
    {
        try {
            $items = $this->parseAndStore($source->name, $this->fetchFeedBody($source->url));
        } catch (\Throwable $e) {
            $source->forceFill([
                'last_run_at' => now(),
                'last_status' => ArticleSource::STATUS_FAILED,
                'last_error'  => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        $source->forceFill([
            'last_run_at' => now(),
            'last_status' => ArticleSource::STATUS_OK,
            'last_error'  => null,
        ])->save();

        usort($items, fn ($a, $b) => ($b['published_at']?->getTimestamp() ?? 0) <=> ($a['published_at']?->getTimestamp() ?? 0));

        return $items;
    }

    /**
     * Fetch a feed's body safely: the address is checked (SafeFeedUrl — public hosts only, ports
     * 80/443, every resolved IP public) before the request and again on each redirect hop; redirects
     * are followed by hand, at most MAX_REDIRECTS; the connection is pinned to the IP that was
     * checked (no second DNS lookup to swap); and reading stops at MAX_FEED_BYTES.
     *
     * @throws \RuntimeException with a plain-words reason.
     */
    private function fetchFeedBody(string $url): string
    {
        $guard   = app(SafeFeedUrl::class);
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $check = $guard->inspect($current);
            if (! $check['ok']) {
                throw new \RuntimeException(($hop === 0 ? '' : 'A redirect led somewhere not allowed. ') . $check['error']);
            }

            $options = ['stream' => true];
            if (! $check['literal']) {
                // Connect to exactly the address that was just checked.
                $options['curl'] = [CURLOPT_RESOLVE => ["{$check['host']}:{$check['port']}:{$check['ips'][0]}"]];
            }

            $response = Http::timeout(15)->connectTimeout(10)->withoutRedirecting()->withOptions($options)->get($current);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = trim($response->header('Location'));
                if ($location === '') {
                    throw new \RuntimeException('The feed redirected without saying where.');
                }
                $current = $this->absoluteUrl($current, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new \RuntimeException("The feed address answered with an error (HTTP {$response->status()}).");
            }

            return $this->readCapped($response->toPsrResponse()->getBody(), (int) $response->header('Content-Length'));
        }

        throw new \RuntimeException('The feed redirected too many times (more than ' . self::MAX_REDIRECTS . ').');
    }

    private function readCapped(StreamInterface $body, int $declaredLength): string
    {
        $tooBig = 'The feed is too large to read (over ' . (self::MAX_FEED_BYTES / 1024 / 1024) . ' MB).';
        if ($declaredLength > self::MAX_FEED_BYTES) {
            throw new \RuntimeException($tooBig);
        }

        $deadline = microtime(true) + self::FETCH_DEADLINE_SECS;
        $out      = '';
        while (! $body->eof()) {
            $out .= $body->read(8192);
            if (strlen($out) > self::MAX_FEED_BYTES) {
                throw new \RuntimeException($tooBig);
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('The feed took too long to download.');
            }
        }
        // The stream is released when the response goes out of scope.

        return $out;
    }

    /** Resolve a Location header (absolute, scheme-relative, root-relative or relative) against the URL it came from. */
    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $p = parse_url($base) ?: [];
        $origin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($p['scheme'] ?? 'http') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';

        return $origin . $dir . $location;
    }

    /**
     * Parse RSS XML and upsert items into article_pool.
     *
     * @return list<array{hash: string, title: string, url: string, snippet: string, content: string, published_at: ?\DateTimeImmutable, source: string}>
     */
    private function parseAndStore(string $source, string $xml): array
    {
        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        libxml_clear_errors();

        if ($feed === false) {
            throw new \RuntimeException('Failed to parse XML');
        }

        $items = [];

        // Standard RSS 2.0: <channel><item>
        if (isset($feed->channel->item)) {
            $items = $feed->channel->item;
        }
        // Atom: <entry>
        elseif (isset($feed->entry)) {
            $items = $feed->entry;
        }

        $found = [];

        foreach ($items as $item) {
            $title       = trim(preg_replace('/\s+/', ' ', PlainText::strip($this->extractText($item, 'title'))) ?? '');
            $link        = $this->extractLink($item);
            $description = $this->extractText($item, 'description');
            $pubDate     = $this->extractText($item, 'pubDate')
                        ?: ($this->extractText($item, 'published') ?: null);

            if ($title === '' || $link === '') {
                continue;
            }

            // Clean description: strip HTML, truncate
            $snippet = $this->cleanSnippet($description);

            // Auto-tag
            $tags = $this->autoTag($title, $snippet);

            // Parse published date
            $publishedAt = null;
            if ($pubDate !== null) {
                try {
                    $publishedAt = new \DateTimeImmutable($pubDate);
                } catch (\Throwable) {
                    // ignore unparseable dates
                }
            }

            $urlHash = hash('sha256', $link);

            // Upsert: update if URL already exists, create if new
            ArticlePool::updateOrCreate(
                ['url_hash' => $urlHash],
                [
                    'source'       => $source,
                    'title'        => mb_substr($title, 0, 255),
                    'url'          => $link,
                    'snippet'      => $snippet !== '' ? $snippet : null,
                    'published_at' => $publishedAt,
                    'tags_json'    => !empty($tags) ? $tags : null,
                    'scraped_at'   => now(),
                ]
            );

            $found[] = [
                'hash'         => $urlHash,
                'title'        => mb_substr($title, 0, 255),
                'url'          => $link,
                'snippet'      => $snippet,
                'content'      => $this->extractFullText($item),
                'published_at' => $publishedAt,
                'source'       => $source,
            ];
        }

        return $found;
    }

    /**
     * Full text from `<content:encoded>` where the feed supplies it (plain text, capped). Empty
     * when it does not — the library then writes from the title + snippet only.
     */
    private function extractFullText(\SimpleXMLElement $item): string
    {
        $content = $item->children(self::CONTENT_NS);
        if (!isset($content->encoded)) {
            return '';
        }

        $text = PlainText::strip((string) $content->encoded);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_substr($text, 0, self::CONTENT_MAX_CHARS);
    }

    /**
     * Extract text content from an XML element.
     */
    private function extractText(\SimpleXMLElement $item, string $field): string
    {
        if (!isset($item->{$field})) {
            return '';
        }

        return trim((string) $item->{$field});
    }

    /**
     * Extract the link URL from an RSS/Atom item.
     */
    private function extractLink(\SimpleXMLElement $item): string
    {
        // RSS 2.0: <link>
        if (isset($item->link)) {
            $link = trim((string) $item->link);
            if ($link !== '') {
                return $link;
            }
        }

        // Atom: <link href="...">
        if (isset($item->link['href'])) {
            return trim((string) $item->link['href']);
        }

        return '';
    }

    /**
     * Strip HTML tags, collapse whitespace, truncate to ~300 chars.
     */
    private function cleanSnippet(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Decode entities and strip HTML until stable (an encoded tag must not survive as a literal one)
        $clean = PlainText::strip($text);

        // Collapse whitespace
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        // Truncate to ~300 chars at word boundary
        if (mb_strlen($clean) > 300) {
            $clean = mb_substr($clean, 0, 297);
            $lastSpace = mb_strrpos($clean, ' ');
            if ($lastSpace !== false && $lastSpace > 200) {
                $clean = mb_substr($clean, 0, $lastSpace);
            }
            $clean .= '...';
        }

        return $clean;
    }

    /**
     * Auto-tag an article by scanning title + snippet for known keywords.
     *
     * Tags with suburbs, towns (parent areas), regions, property types, and topics.
     * An article mentioning "Margate" will be tagged with the Margate town,
     * making it match ALL suburbs in the Greater Margate area.
     *
     * @return array{suburbs?: string[], towns?: string[], regions?: string[], property_types?: string[], topics?: string[]}
     */
    private function autoTag(string $title, string $snippet): array
    {
        $searchText = mb_strtolower($title . ' ' . $snippet);
        $tags = [];

        foreach (self::SUBURBS as $suburb) {
            if (mb_stripos($searchText, mb_strtolower($suburb)) !== false) {
                $tags['suburbs'][] = $suburb;
            }
        }

        // Town-level tagging: check for town names from the mapping config.
        // An article mentioning "Margate" should match all Margate-area suburbs.
        foreach (SuburbMapper::allTowns() as $town) {
            if (mb_stripos($searchText, mb_strtolower($town)) !== false) {
                $tags['towns'][] = $town;
            }
        }

        foreach (self::REGIONS as $region) {
            if (mb_stripos($searchText, mb_strtolower($region)) !== false) {
                $tags['regions'][] = $region;
            }
        }

        foreach (self::PROPERTY_TYPES as $type) {
            if (mb_stripos($searchText, $type) !== false) {
                $tags['property_types'][] = $type;
            }
        }

        foreach (self::TOPICS as $topic) {
            if (mb_stripos($searchText, $topic) !== false) {
                $tags['topics'][] = $topic;
            }
        }

        return $tags;
    }
}
