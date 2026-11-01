<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\MicAgencySetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-528 (mic-work-tab.md 13.4): the wording-based sole-mandate match, stored on the advert (`prospecting_listings.sole_mandate_wording`).
 * The agency's mandate words (Setting) as a WHOLE PHRASE, case-insensitive, over the advert's snippet and full description. Set on arrival of a snippet
 * (ProspectingListingLifecycleObserver::saving) and re-run for the whole agency when its words change or by `mic:match-sole-mandate`.
 */
final class SoleMandateMatcher
{
    /** @var array<int,list<string>> per request */
    private static array $words = [];

    public static function wordsFor(int $agencyId): array
    {
        return self::$words[$agencyId] ??= MicAgencySetting::mandateWordsFor($agencyId);
    }

    public static function forgetWords(): void
    {
        self::$words = [];
    }

    public static function matches(?string $snippet, ?string $description, int $agencyId, ?string $advertiser = null): bool
    {
        $w = self::wordsFor($agencyId);

        return MicAdvertFilters::mentionsMandate($snippet, $w, $advertiser) || MicAdvertFilters::mentionsMandate($description, $w, $advertiser);
    }

    /**
     * The earliest date a property was seen as sole mandate: the first time the wording matched on any of its LIVE adverts (`sole_mandate_first_seen_at`, the capture date of
     * the first snippet / description that matched), or the received date of a mandated alert - whichever is earlier. Null when nothing flags it.
     *
     * @param iterable<object> $liveAdverts rows with sole_mandate_wording and sole_mandate_first_seen_at
     * @param iterable<?string> $alertDates received dates of the file's sole-mandate alerts
     */
    public static function since(iterable $liveAdverts, iterable $alertDates = []): ?Carbon
    {
        $dates = [];
        foreach ($liveAdverts as $a) {
            if (! empty($a->sole_mandate_wording) && ! empty($a->sole_mandate_first_seen_at)) {
                $dates[] = Carbon::parse($a->sole_mandate_first_seen_at);
            }
        }
        foreach ($alertDates as $d) {
            if (! empty($d)) {
                $dates[] = Carbon::parse($d);
            }
        }

        return $dates === [] ? null : collect($dates)->sort()->first();
    }

    /** whole days from the date we first saw it as sole mandate to today */
    public static function daysSince(?Carbon $since, ?Carbon $now = null): ?int
    {
        return $since === null ? null : (int) abs($since->copy()->startOfDay()->diffInDays(($now ?? now())->copy()->startOfDay()));
    }

    /**
     * The ONE rule for "does this text say sole mandate", for the alert-email parsers (P24, PrivateProperty) as well as the advert wording: the text (tags stripped,
     * the advertiser's own name removed first) must hold one of the mandate phrases - "sole" or "exclusive" next to mandate / agent / agency. "Mandated", "mandate",
     * "open mandate" never flag. With no agency the shipped phrases are used.
     */
    public static function textSaysSoleMandate(?string $html, ?string $advertiser = null, ?int $agencyId = null): bool
    {
        $text = preg_replace('~https?://[^\s"\'<>]+~i', ' ', (string) $html) ?? '';
        $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div|\/tr|\/td)[^>]*>/i', "\n", $text)));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return MicAdvertFilters::mentionsMandate($text, $agencyId ? self::wordsFor($agencyId) : MicAgencySetting::DEFAULTS['mandate_words'], $advertiser);
    }

    /**
     * Match every advert of the agency that has text. Returns the counts; writes only when $apply.
     *
     * @return array{checked:int, matched:int, was_matched:int, changed:int}
     */
    public function rematchAgency(int $agencyId, bool $apply): array
    {
        self::forgetWords();
        $out = ['checked' => 0, 'matched' => 0, 'was_matched' => 0, 'changed' => 0];
        DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('description_snippet')->orWhereNotNull('description'))
            ->orderBy('id')->select('id', 'description_snippet', 'description', 'sole_mandate_wording', 'agency_name', 'sole_mandate_first_seen_at', 'details_read_at')
            ->chunkById(1000, function ($rows) use ($agencyId, $apply, &$out) {
                foreach ($rows as $r) {
                    $now = self::matches($r->description_snippet, $r->description, $agencyId, $r->agency_name);
                    $out['checked']++;
                    $out['matched'] += $now ? 1 : 0;
                    $out['was_matched'] += $r->sole_mandate_wording ? 1 : 0;
                    // the date we first saw it as sole mandate: kept once set; else when the page details were read; else today (no dated capture of the text is held)
                    $since = $now ? ($r->sole_mandate_first_seen_at ?? $r->details_read_at ?? now()->toDateTimeString()) : null;
                    if ((int) $r->sole_mandate_wording !== (int) $now || $r->sole_mandate_wording === null || (($r->sole_mandate_first_seen_at === null) !== ($since === null))) {
                        $out['changed']++;
                        if ($apply) {
                            DB::table('prospecting_listings')->where('id', $r->id)->update(['sole_mandate_wording' => $now ? 1 : 0, 'sole_mandate_first_seen_at' => $since]);
                        }
                    }
                }
            });

        return $out;
    }
}
