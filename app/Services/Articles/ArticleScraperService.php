<?php

namespace App\Services\Articles;

use App\Models\ArticlePool;
use App\Support\SuburbMapper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ArticleScraperService
{
    /**
     * RSS feeds to scrape. Key = source label, value = feed URL.
     */
    private const FEEDS = [
        'BusinessTech Property'   => 'https://businesstech.co.za/news/category/property/feed/',
        'Daily Investor Property' => 'https://dailyinvestor.com/category/south-africa/property/feed/',
        'IOL Property'            => 'https://www.iol.co.za/news/south-africa/rss',
        'Property24 Advice'       => 'https://www.property24.com/articles/rss',
        'MyBroadband Property'    => 'https://mybroadband.co.za/news/category/property/feed/',
    ];

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
     * Scrape all configured RSS feeds and return summary stats.
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

        foreach (self::FEEDS as $source => $feedUrl) {
            $stats['feeds_attempted']++;

            try {
                $count = $this->scrapeFeed($source, $feedUrl);
                $stats['feeds_succeeded']++;
                $stats['articles_upserted'] += $count;
            } catch (\Throwable $e) {
                $msg = "[{$source}] {$e->getMessage()}";
                $stats['errors'][] = $msg;
                Log::warning("ArticleScraperService: {$msg}");
            }
        }

        return $stats;
    }

    /**
     * Scrape a single RSS feed and upsert articles into article_pool.
     */
    private function scrapeFeed(string $source, string $feedUrl): int
    {
        $response = Http::timeout(15)->connectTimeout(10)->get($feedUrl);

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()} fetching {$feedUrl}");
        }

        $xml = $response->body();

        return $this->parseAndStore($source, $xml);
    }

    /**
     * Parse RSS XML and upsert items into article_pool.
     */
    private function parseAndStore(string $source, string $xml): int
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

        $upserted = 0;

        foreach ($items as $item) {
            $title       = $this->extractText($item, 'title');
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

            $upserted++;
        }

        return $upserted;
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

        // Strip HTML
        $clean = strip_tags($text);

        // Decode entities
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

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
