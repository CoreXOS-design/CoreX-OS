<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\MicCrawl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads of the crawl record (mic-work-tab.md 11.3.C and E). Every query names the agency.
 *
 * A crawl may be relied on only when it is COMPLETE (every page read) and UNFILTERED (the search covered the whole stock, not a price band or a
 * bedroom count) - a filtered walk that reached its last page is still not a complete picture of a suburb.
 */
final class CrawlRecords
{
    /** P24: an `sp=` filter bag may carry only the sort (`so`). */
    private const P24_ALLOWED_SP_KEYS = ['so'];

    /** PP: query keys that do not narrow the stock. */
    private const PP_ALLOWED_QUERY_KEYS = ['si', 'sorttype', 'sortorder', 'page'];

    /** True when the search URL narrows the stock (price, beds, type, "exclude sold / under offer", ...). */
    public static function isFiltered(string $portal, ?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return true; // unknown = not provable as complete
        }
        $parts = parse_url($url) ?: [];
        $path = strtolower((string) ($parts['path'] ?? ''));
        parse_str((string) ($parts['query'] ?? ''), $query);

        if ($portal === 'p24') {
            $first = explode('/', trim($path, '/'))[0] ?? '';
            if (! in_array($first, ['for-sale', 'to-rent'], true)) {
                return true; // /houses-for-sale/, /apartments-for-sale/, ... is a type filter
            }
            if (isset($query['sp'])) {
                parse_str(urldecode((string) $query['sp']), $sp);
                foreach (array_keys($sp) as $k) {
                    if (! in_array(strtolower((string) $k), self::P24_ALLOWED_SP_KEYS, true)) {
                        return true;
                    }
                }
            }

            return false;
        }

        foreach (array_keys($query) as $k) {
            if (! in_array(strtolower((string) $k), self::PP_ALLOWED_QUERY_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    /** 'rent' for a to-rent search, else 'sale'. */
    public static function listingTypeFromUrl(?string $url): string
    {
        return preg_match('#/to-rent(/|$|\?)#i', (string) $url) === 1 ? 'rent' : 'sale';
    }

    /**
     * The latest usable crawl of this suburb (our p24_suburbs.id) on this portal whose finish falls within $withinDays days up to and including
     * $before. For cc5's DOM rule: "was this suburb completely read shortly before the advert's date?"
     */
    public function completeCrawlWithin(int $agencyId, string $portal, int $suburbId, \DateTimeInterface $before, int $withinDays, string $listingType = 'sale'): ?MicCrawl
    {
        $to = \Carbon\Carbon::instance(\DateTimeImmutable::createFromInterface($before));
        $from = $to->copy()->subDays(max(0, $withinDays));

        return $this->usable($agencyId, $portal, $suburbId, $listingType)
            ->whereBetween('mic_crawls.finished_at', [$from, $to])
            ->orderByDesc('mic_crawls.finished_at')->first();
    }

    public function latestCompleteCrawl(int $agencyId, string $portal, int $suburbId, string $listingType = 'sale'): ?MicCrawl
    {
        return $this->usable($agencyId, $portal, $suburbId, $listingType)->orderByDesc('mic_crawls.finished_at')->first();
    }

    /**
     * For tile 11: one row per suburb and portal - the last usable crawl (suburb_id, portal, finished_at). Suburbs never crawled are not listed.
     *
     * @return Collection<int, object{suburb_id:int, suburb_name:?string, portal:string, finished_at:string}>
     */
    public function lastCrawlBySuburb(int $agencyId, string $listingType = 'sale'): Collection
    {
        return DB::table('mic_crawl_suburbs as s')
            ->join('mic_crawls as c', 'c.id', '=', 's.mic_crawl_id')
            ->where('c.agency_id', $agencyId)->where('s.agency_id', $agencyId)->where('c.listing_type', $listingType)
            ->where('c.is_complete', 1)->where('c.is_filtered', 0)->where('c.adverts_seen', '>', 0)->whereNotNull('s.suburb_id')
            ->groupBy('s.suburb_id', 's.portal')
            ->selectRaw('s.suburb_id, MAX(s.suburb_name) as suburb_name, s.portal, MAX(c.finished_at) as finished_at')
            ->get();
    }

    private function usable(int $agencyId, string $portal, int $suburbId, string $listingType)
    {
        return MicCrawl::query()
            ->join('mic_crawl_suburbs as s', 's.mic_crawl_id', '=', 'mic_crawls.id')
            ->where('mic_crawls.agency_id', $agencyId)->where('s.agency_id', $agencyId)
            ->where('mic_crawls.portal', $portal)->where('mic_crawls.listing_type', $listingType)
            ->where('mic_crawls.is_complete', 1)->where('mic_crawls.is_filtered', 0)->where('mic_crawls.adverts_seen', '>', 0)
            ->where('s.suburb_id', $suburbId)
            ->select('mic_crawls.*');
    }
}
