<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\MicCrawl;
use App\Models\MicCrawlSuburb;
use App\Models\ProspectingListing;
use App\Models\ProspectingSearch;

/**
 * Keeps the crawl record (mic_crawls) up to date from the search row the importer upserts (cc3's crawl_* columns, mic-work-tab.md 11.2.B / 11.3.A).
 * `sync()` runs on every saved batch; `finalise()` is called by the importer's existing complete-capture reconcile on the final batch.
 */
final class CrawlRecorder
{
    /** batch 1's own adverts are stamped a moment before the crawl row exists, so a crawl "starts" this long before its first batch */
    public const START_MARGIN_MINUTES = 5;

    public function sync(ProspectingSearch $s): ?MicCrawl
    {
        if (! $s->agency_id || ! $s->id) {
            return null;
        }
        $crawl = $this->crawlFor($s);
        $crawl->pages_read = max((int) $crawl->pages_read, (int) ($s->crawl_page ?? $s->pages_captured ?? 0));
        if ($s->crawl_pages_total) {
            $crawl->pages_total = max((int) $crawl->pages_total, (int) $s->crawl_pages_total);
        }
        $crawl->adverts_seen = $this->seen($s)->count();
        if ($s->crawl_complete && ($crawl->pages_total === null || $crawl->pages_read >= $crawl->pages_total)) {
            $crawl->is_complete = true;
            $crawl->finished_at = $s->crawl_finished_at ?? $crawl->finished_at ?? now();
        }
        $crawl->save();

        return $crawl;
    }

    /**
     * The importer says the capture is complete (the extension's final batch). Marks the crawl complete, counts what it saw and records the suburbs
     * those adverts are in. $persist = false builds the same picture in memory (a dry run).
     */
    public function finalise(ProspectingSearch $s, bool $persist = true): MicCrawl
    {
        $crawl = $this->crawlFor($s);
        $crawl->adverts_seen = $this->seen($s)->count();
        $crawl->pages_read = max((int) $crawl->pages_read, (int) ($s->crawl_page ?? $s->pages_captured ?? 0));
        $crawl->pages_total = max((int) $crawl->pages_total, (int) ($s->crawl_pages_total ?? 0)) ?: null;
        $crawl->is_complete = true;
        $crawl->finished_at = $s->crawl_finished_at ?? now();

        $subs = $this->seen($s)->get(['p24_suburb_id', 'suburb'])
            ->map(fn ($l) => ['id' => $l->p24_suburb_id ? (int) $l->p24_suburb_id : null, 'name' => trim((string) $l->suburb)])
            ->unique(fn ($x) => ($x['id'] ?? 'n') . '|' . mb_strtolower($x['name']))->values();

        if (! $persist) {
            $crawl->setRelation('suburbs', $subs->map(fn ($x) => new MicCrawlSuburb(['suburb_id' => $x['id'], 'suburb_name' => $x['name'], 'portal' => $crawl->portal, 'agency_id' => $crawl->agency_id])));

            return $crawl;
        }
        $crawl->save();
        MicCrawlSuburb::query()->where('mic_crawl_id', $crawl->id)->delete();
        foreach ($subs as $x) {
            MicCrawlSuburb::query()->create(['mic_crawl_id' => $crawl->id, 'agency_id' => $crawl->agency_id, 'portal' => $crawl->portal, 'suburb_id' => $x['id'], 'suburb_name' => $x['name'] ?: null]);
        }

        return $crawl->load('suburbs');
    }

    private function crawlFor(ProspectingSearch $s): MicCrawl
    {
        $session = $s->crawl_session_id ?: 'legacy-' . $s->id;
        $crawl = MicCrawl::query()->firstOrNew(['agency_id' => (int) $s->agency_id, 'session_id' => $session]);
        $portal = (string) ($s->crawl_portal ?: $s->portal_source);
        if (! $crawl->exists) {
            $first = $s->crawl_started_at ?? $s->created_at ?? now();
            $crawl->fill([
                'user_id' => $s->user_id, 'portal' => $portal, 'started_at' => \Carbon\Carbon::parse($first)->subMinutes(self::START_MARGIN_MINUTES),
                'pages_read' => 0, 'adverts_seen' => 0, 'is_complete' => false,
            ]);
        }
        $crawl->search_url = $s->search_url;
        $crawl->listing_type = CrawlRecords::listingTypeFromUrl($s->search_url);
        $crawl->is_filtered = CrawlRecords::isFiltered($portal, $s->search_url);

        return $crawl;
    }

    private function seen(ProspectingSearch $s)
    {
        return ProspectingListing::withoutGlobalScopes()->where('agency_id', $s->agency_id)->where('last_search_id', $s->id);
    }
}
