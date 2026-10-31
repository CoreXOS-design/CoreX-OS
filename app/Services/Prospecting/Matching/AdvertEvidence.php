<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Address\AddressFacts;
use App\Services\Address\AddressMatchScorer;
use App\Services\Address\AddressParser;
use App\Services\Address\SuburbResolver;
use App\Models\Prospecting\TrackedPropertyAddress;
use Illuminate\Support\Facades\DB;

/**
 * AT-520 (conductor walk 31 Oct, "13 Newport Road" / "13 Newport Road Road"): two files at the same address are NOT merged automatically when what their
 * adverts say contradicts it - different bedrooms (5 vs 6), or prices further apart than the agency's single-property price gap (R700k vs R1.499m).
 * They go to the review list with the contradiction named. Files with no adverts (nothing to contradict) are unaffected.
 */
final class AdvertEvidence
{
    /** @var array<int,array<string,mixed>> */
    private array $settingsMemo = [];

    public function contradiction(int $agencyId, int $fileA, int $fileB, float $spread): ?string
    {
        $rows = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', [$fileA, $fileB])
            ->get(['tracked_property_id as fid', 'bedrooms as beds', 'price'])->groupBy('fid');
        if ($rows->count() < 2) {
            return null;
        }
        $beds = fn (int $f) => $rows[$f]->pluck('beds')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->unique()->values();
        $a = $beds($fileA);
        $b = $beds($fileB);
        if ($a->isNotEmpty() && $b->isNotEmpty() && $a->intersect($b)->isEmpty()) {
            return 'the adverts say different bedrooms (' . $a->implode('/') . ' and ' . $b->implode('/') . ')';
        }
        $prices = $rows->flatten(1)->pluck('price')->filter(fn ($p) => $p > 0);
        if ($prices->count() >= 2 && $prices->min() > 0 && $prices->max() / $prices->min() > $spread) {
            return 'the prices are ' . round($prices->max() / $prices->min(), 1) . ' times apart (R' . number_format((float) $prices->min(), 0, '.', ',') . ' and R' . number_format((float) $prices->max(), 0, '.', ',') . ')';
        }

        return null;
    }

    /**
     * AT-520 4.8: does what the ADVERT itself says about where it is contradict the property file it sits on? Uses the same address reader and scorer as the
     * matcher: a different street number, a different street, or a suburb that is not the file's (and not a neighbour) can never be the same property.
     * Returns null when nothing contradicts (or the advert / file has nothing to compare).
     *
     * @param  array{address?:?string, suburb?:?string, street_number?:?string, street_name?:?string} $advert
     * @return array{kind:string, reason:string}|null  kind: number | street | region
     */
    public function advertVsFile(int $agencyId, array $advert, TrackedProperty $file): ?array
    {
        if ($this->obviouslyConsistent($advert, $file)) {
            return null; // the cheap pure-text check passed: no need for the full scorer (this runs on every advert of an agency)
        }
        $a = AddressFacts::fromPayload(array_filter($advert, fn ($v) => $v !== null && trim((string) $v) !== ''));
        $b = AddressFacts::fromModel($file);
        $col = app(AddressMatchScorer::class)->score($a, $b, ($this->settingsMemo[$agencyId] ??= AddressMatchSetting::forAgency($agencyId)))['columns'];
        $fileLine = trim(($file->street_number ? $file->street_number . ' ' : '') . (string) $file->street_name . ($file->suburb ? ', ' . $file->suburb : ''));
        $advLine = trim((string) ($advert['address'] ?? '')) . (trim((string) ($advert['suburb'] ?? '')) !== '' ? ', ' . trim((string) $advert['suburb']) : '');
        if ($col['suburb'] === 'differ') {
            // a different suburb NAME inside the same town is naming (an estate in Port Edward, Margate vs Margate Beach); only a different TOWN is a real region mismatch
            $ca = SuburbResolver::resolve($advert['suburb'] ?? null)['p24_city_id'];
            $cb = $file->p24_city_id ?: SuburbResolver::resolve($file->suburb)['p24_city_id'];
            if ($ca !== null && $cb !== null && (int) $ca !== (int) $cb) {
                return ['kind' => 'region', 'reason' => 'the advert is in ' . trim((string) ($advert['suburb'] ?? '?')) . ' but the property is in ' . trim((string) $file->suburb) . ' (a different town)'];
            }
        }
        if ($col['street'] === 'differ') {
            return ['kind' => 'street', 'reason' => "the advert's street (\"" . $advLine . "\") is not the property's (\"" . $fileLine . "\")"];
        }
        if ($col['number'] === 'differ') {
            return ['kind' => 'number', 'reason' => "the advert's street number (\"" . $advLine . "\") is not the property's (\"" . $fileLine . "\")"];
        }

        return null;
    }

    /** street number + street core + suburb spelling all agree (or one side has nothing to compare): certainly not a contradiction */
    private function obviouslyConsistent(array $advert, TrackedProperty $file): bool
    {
        // the advert says what the file says (complex-style lines such as "71 Butleigh, 2099 West Drive"): nothing to contradict
        $norm = fn (?string $t) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $t));
        $advText = $norm($advert['address'] ?? null);
        $fileText = $norm(trim((string) $file->street_number . ' ' . (string) $file->street_name));
        $suburbSame = trim((string) ($advert['suburb'] ?? '')) === '' || trim((string) $file->suburb) === ''
            || array_intersect(TrackedPropertyAddress::suburbSpellingKeys((string) $advert['suburb']), TrackedPropertyAddress::suburbSpellingKeys((string) $file->suburb)) !== [];
        if ($suburbSame && $advText !== '' && $fileText !== '' && (str_contains($advText, $fileText) || str_contains($fileText, $advText))) {
            return true;
        }
        // the file's number is one of the numbers in the advert's line (the unit of a flat is often held as the street number): not a different house
        $advNumbers = [];
        if (preg_match_all('/(?<![\w\/-])(\d+[a-z]?)(?![\w\/-])/i', (string) ($advert['address'] ?? ''), $m)) {
            $advNumbers = array_map(fn ($n) => AddressParser::numberKey($n), $m[1]);
        }
        $parser = new AddressParser();
        $p = $parser->parse(['address' => $advert['address'] ?? null, 'suburb' => $advert['suburb'] ?? null]);
        [$fileNum, $fileCore] = $parser->numberAndCore($file->street_number, $file->street_name);
        $advNum = AddressParser::numberKey($p['street_number'] ?? null);
        $advCore = $p['street_core'] ?? null;
        $numberOk = $advNum === null || $fileNum === null || $advNum === $fileNum || in_array($fileNum, $advNumbers, true);
        $streetOk = ($advCore === null || $fileCore === null || $advCore === $fileCore) && $numberOk;
        $advSub = trim((string) ($advert['suburb'] ?? ''));
        $fileSub = trim((string) $file->suburb);
        $suburbOk = $advSub === '' || $fileSub === '' || array_intersect(TrackedPropertyAddress::suburbSpellingKeys($advSub), TrackedPropertyAddress::suburbSpellingKeys($fileSub)) !== [];

        return $streetOk && $suburbOk;
    }
}
