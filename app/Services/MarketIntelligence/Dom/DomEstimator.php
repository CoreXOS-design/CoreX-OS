<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Dom;

use App\Services\Prospecting\CrawlRecords;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The days-on-market rule, exactly as specced (.ai/specs/mic-work-tab.md goal + 11.1):
 *   - portal listed date known            -> exact, "45 days"           (mode listed)
 *   - else first_seen, and the suburb had a COMPLETE unfiltered crawl on that portal that finished within `window` days BEFORE first_seen
 *     (so the advert was provably not there yet)               -> "~45 days"   (mode approx, accurate to the window)
 *   - else first_seen only (no such crawl, advert already there on our first read, or a crawl gap > window) -> "45+ days" (mode minimum)
 *   - no first_seen at all                                       -> unknown
 * The crawl question is answered by cc4's CrawlRecords::completeCrawlWithin (the crawl record, 11.3.E). Until a suburb has a crawl record
 * every advert is a minimum "N+" - never a made-up exact number.
 */
final class DomEstimator
{
    /** @var array<string, bool> portal|suburb => has any usable crawl */
    private array $anyCrawl = [];

    public function __construct(private readonly CrawlRecords $crawls)
    {
    }

    /**
     * @param  object{portal_source:string,listed_date:?string,first_seen_at:?string,p24_suburb_id:?int}  $advert
     */
    public function forAdvert(int $agencyId, object $advert, int $windowDays, ?CarbonInterface $now = null): DomResult
    {
        $now ??= Carbon::now();

        if (! empty($advert->listed_date)) {
            $days = max(0, (int) Carbon::parse($advert->listed_date)->startOfDay()->diffInDays($now->copy()->startOfDay()));

            return new DomResult($days, DomResult::LISTED, $days . ' days', 'portal_listed_date');
        }
        if (empty($advert->first_seen_at)) {
            return DomResult::unknown();
        }

        $first = Carbon::parse($advert->first_seen_at);
        $days = max(0, (int) $first->diffInDays($now));
        $approx = false;

        $suburb = $advert->p24_suburb_id !== null ? (int) $advert->p24_suburb_id : null;
        if ($suburb !== null) {
            $key = $agencyId . '|' . $advert->portal_source . '|' . $suburb;
            $this->anyCrawl[$key] ??= $this->crawls->latestCompleteCrawl($agencyId, (string) $advert->portal_source, $suburb) !== null;
            if ($this->anyCrawl[$key]) {
                $approx = $this->crawls->completeCrawlWithin($agencyId, (string) $advert->portal_source, $suburb, $first, $windowDays) !== null;
            }
        }

        return $approx
            ? new DomResult($days, DomResult::APPROX, '~' . $days . ' days', 'first_seen')
            : new DomResult($days, DomResult::MINIMUM, $days . '+ days', 'first_seen');
    }

    /**
     * A file's DOM = its OLDEST advert's (ties: the more certain mode wins).
     *
     * @param  iterable<object>  $adverts
     */
    public function forAdverts(int $agencyId, iterable $adverts, int $windowDays, ?CarbonInterface $now = null): DomResult
    {
        $best = null;
        foreach ($adverts as $a) {
            $r = $this->forAdvert($agencyId, $a, $windowDays, $now);
            if ($r->days === null) {
                continue;
            }
            if ($best === null || $r->days > $best->days || ($r->days === $best->days && $this->rank($r->mode) > $this->rank($best->mode))) {
                $best = $r;
            }
        }

        return $best ?? DomResult::unknown();
    }

    private function rank(string $mode): int
    {
        return match ($mode) {
            DomResult::LISTED => 3, DomResult::APPROX => 2, DomResult::MINIMUM => 1, default => 0,
        };
    }
}
