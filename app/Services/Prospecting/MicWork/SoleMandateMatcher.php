<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\MicAgencySetting;
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
            ->orderBy('id')->select('id', 'description_snippet', 'description', 'sole_mandate_wording', 'agency_name')
            ->chunkById(1000, function ($rows) use ($agencyId, $apply, &$out) {
                foreach ($rows as $r) {
                    $now = self::matches($r->description_snippet, $r->description, $agencyId, $r->agency_name);
                    $out['checked']++;
                    $out['matched'] += $now ? 1 : 0;
                    $out['was_matched'] += $r->sole_mandate_wording ? 1 : 0;
                    if ((int) $r->sole_mandate_wording !== (int) $now || $r->sole_mandate_wording === null) {
                        $out['changed']++;
                        if ($apply) {
                            DB::table('prospecting_listings')->where('id', $r->id)->update(['sole_mandate_wording' => $now ? 1 : 0]);
                        }
                    }
                }
            });

        return $out;
    }
}
