<?php

namespace App\Services\P24;

use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;

/**
 * Read-only suburb → city → province chain walker.
 *
 * Two DIFFERENT id spaces both get called "the P24 suburb id" in this codebase
 * and must never be mixed:
 *   - `p24_suburbs.id`    — OUR internal auto-increment PK. This is what the
 *      manual property forms store on `properties.p24_suburb_id` (chosen via
 *      a dropdown built from our own table, verified by AppliesP24Location).
 *   - `p24_suburbs.p24_id` — Property24's OWN external suburb id, as it
 *      appears in inbound P24 CSV/API payloads (e.g. the CSV `SuburbId`
 *      column).
 * Resolving one as if it were the other silently lands on an unrelated
 * suburb whenever the numbers happen to collide — found 2026-08-16: the
 * P24 CSV importer looked up every listing's raw external SuburbId against
 * `p24_suburbs.id`, so suburb/city text landed wrong on effectively every
 * CSV-imported property (100% of the 4,753 confirmed on the Demo Agency Test
 * import), including several that WERE also missing suburb/city entirely
 * (the first half of this same bug, fixed earlier the same day).
 *
 * `resolve()` takes our internal id (manual forms). `resolveByP24Id()` takes
 * P24's external id (CSV/API import). Both are read-only and never throw —
 * shared by AppliesP24Location (which wraps `resolve()` with its own
 * ValidationExceptions) and ConfirmP24PropertyRowJob (which must never throw
 * or guess: an unresolved id just leaves suburb/city blank).
 */
class P24LocationResolver
{
    /**
     * @return array{suburb: P24Suburb, city: P24City, province: ?P24Province}|null
     */
    public static function resolve(int $suburbId): ?array
    {
        return self::fromSuburb(P24Suburb::find($suburbId));
    }

    /**
     * @return array{suburb: P24Suburb, city: P24City, province: ?P24Province}|null
     */
    public static function resolveByP24Id(int $p24Id): ?array
    {
        return self::fromSuburb(P24Suburb::where('p24_id', $p24Id)->first());
    }

    /**
     * @return array{suburb: P24Suburb, city: P24City, province: ?P24Province}|null
     */
    private static function fromSuburb(?P24Suburb $suburb): ?array
    {
        if (!$suburb || !$suburb->p24_city_id) {
            return null;
        }

        $city = P24City::find($suburb->p24_city_id);
        if (!$city) {
            return null;
        }

        $province = $city->p24_province_id ? P24Province::find($city->p24_province_id) : null;

        return ['suburb' => $suburb, 'city' => $city, 'province' => $province];
    }
}
