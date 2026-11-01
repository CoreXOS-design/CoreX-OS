<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AT-520 4.13 (Johan: "rather UNDER-match than OVER-match"; 4 Emtunzi Drive carried a 5-bed R5.3m house next to a 2-bed R950k flat). The one test every
 * automatic rule that puts an advert on a property file runs first: the advert must fit EVERY advert already on that file - not just the others in its
 * own P24 group. It fits when
 *   - the property type is in the same family (residential = house / townhouse / apartment / flat / duplex / simplex / cluster / penthouse - never split on type
 *     among them; vacant land; commercial / industrial; farm / smallholding), and adverts that BOTH carry the file's own street address are never split on type at all,
 *   - the bedrooms are within the agency's gap (default 1),
 *   - the asking prices (same sale / rent kind) are within the agency's price band (default 40%: the dearer is at most 40% above the cheaper),
 *   - the floor sizes - and, separately, the stand sizes - are within the size band (default 40%) when BOTH adverts show one.
 * Something missing on either side is no contradiction (nothing to compare). A failure means no move: the caller writes a review item with the reason.
 */
final class MergeGuard
{
    /** word fragments -> family, first hit wins */
    private const FAMILIES = [
        'land'        => ['land', 'plot', 'stand'],
        'commercial'  => ['commercial', 'industrial', 'office', 'retail', 'warehouse'],
        'farm'        => ['farm', 'smallholding'],
        'residential' => ['apartment', 'flat', 'townhouse', 'duplex', 'simplex', 'penthouse', 'studio', 'cluster', 'maisonette', 'sectional', 'house', 'freehold'],
    ];

    /** @var array<int,array<string,mixed>> */
    private array $settingsMemo = [];

    public static function family(?string $type): ?string
    {
        $t = mb_strtolower(trim((string) $type));
        if ($t === '') {
            return null;
        }
        foreach (self::FAMILIES as $family => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return $family;
                }
            }
        }

        return 'other:' . $t;
    }

    /** @return array<string,mixed> */
    public function settings(int $agencyId): array
    {
        return $this->settingsMemo[$agencyId] ??= AddressMatchSetting::forAgency($agencyId);
    }

    /**
     * The first reason advert $a and advert $b cannot be one property, or null. Each is an object/array with ptype, beds, price, floor, erf, ltype.
     *
     * @param  array<string,mixed> $s the agency's matching settings
     */
    public function pairConflict(object $a, object $b, array $s): ?string
    {
        $fa = self::family($a->ptype ?? null);
        $fb = self::family($b->ptype ?? null);
        // both adverts carry the file's own street address (same number, same street): their type wording is never a reason to split them
        $sameAddress = ($a->at_file ?? null) !== null && ($a->at_file ?? null) === ($b->at_file ?? null);
        if (! $sameAddress && $fa !== null && $fb !== null && $fa !== $fb) {
            return 'the property types differ (' . trim((string) $a->ptype) . ' and ' . trim((string) $b->ptype) . ')';
        }
        if (isset($a->beds, $b->beds) && abs((int) $a->beds - (int) $b->beds) > (int) $s['merge_beds_gap']) {
            return 'the bedrooms differ (' . (int) $a->beds . ' and ' . (int) $b->beds . ')';
        }
        $pa = (float) ($a->price ?? 0);
        $pb = (float) ($b->price ?? 0);
        if ($pa > 0 && $pb > 0 && ! (($a->ltype ?? '') !== '' && ($b->ltype ?? '') !== '' && $a->ltype !== $b->ltype) && max($pa, $pb) > min($pa, $pb) * (1 + (int) $s['merge_price_band_pct'] / 100)) {
            return 'the prices are too far apart (R' . number_format(min($pa, $pb), 0, '.', ',') . ' and R' . number_format(max($pa, $pb), 0, '.', ',') . ')';
        }
        foreach ([['floor', 'floor sizes'], ['erf', 'stand sizes']] as [$k, $label]) {
            $sa = (float) ($a->{$k} ?? 0);
            $sb = (float) ($b->{$k} ?? 0);
            if ($sa > 0 && $sb > 0 && max($sa, $sb) > min($sa, $sb) * (1 + (int) $s['merge_size_band_pct'] / 100)) {
                return 'the ' . $label . ' differ (' . round(min($sa, $sb)) . ' and ' . round(max($sa, $sb)) . ' m2)';
            }
        }

        return null;
    }

    /**
     * Does any advert in $incoming clash with any advert on $fileId (except itself)? Returns a plain sentence naming both adverts, or null.
     *
     * @param  iterable<object> $incoming adverts as returned by advertsOf()
     */
    public function conflictWithFile(int $agencyId, int $fileId, iterable $incoming): ?string
    {
        $s = $this->settings($agencyId);
        $onFile = $this->advertsOf($agencyId, [$fileId])[$fileId] ?? collect();
        $key = $this->fileAddressKeys($agencyId, [$fileId])[$fileId] ?? null;
        foreach ($incoming as $a) {
            $a->at_file ??= $key !== null && self::saysAddress((string) ($a->address ?? $a->advert_address ?? ''), $key) ? $key : null;
            foreach ($onFile as $b) {
                if ($b->ref === $a->ref) {
                    continue;
                }
                if (($why = $this->pairConflict($a, $b, $s)) !== null) {
                    return $this->describe($a) . ' does not fit ' . $this->describe($b) . ' on that property: ' . $why;
                }
            }
        }

        return null;
    }

    /** Whole-file test (a file merge): every advert of A against every advert of B. */
    public function fileVsFile(int $agencyId, int $fileA, int $fileB): ?string
    {
        $m = $this->advertsOf($agencyId, [$fileA, $fileB]);

        return $this->conflictBetween($agencyId, $m[$fileA] ?? collect(), $m[$fileB] ?? collect());
    }

    /** @param Collection<int,object> $as @param Collection<int,object> $bs */
    public function conflictBetween(int $agencyId, Collection $as, Collection $bs): ?string
    {
        $s = $this->settings($agencyId);
        foreach ($as as $a) {
            foreach ($bs as $b) {
                if ($a->ref !== $b->ref && ($why = $this->pairConflict($a, $b, $s)) !== null) {
                    return $this->describe($a) . ' does not fit ' . $this->describe($b) . ': ' . $why;
                }
            }
        }

        return null;
    }

    /**
     * Split one file's adverts into groups that fit together (the first advert of a group must fit every later member). $adverts is in priority order:
     * the order decides who seeds a group, never whether a clash is tolerated.
     *
     * @param  Collection<int,object> $adverts
     * @return list<list<object>>
     */
    public function partition(int $agencyId, Collection $adverts): array
    {
        $s = $this->settings($agencyId);
        $groups = [];
        foreach ($adverts as $a) {
            foreach ($groups as $i => $g) {
                $fits = true;
                foreach ($g as $b) {
                    if ($this->pairConflict($a, $b, $s) !== null) {
                        $fits = false;
                        break;
                    }
                }
                if ($fits) {
                    $groups[$i][] = $a;
                    continue 2;
                }
            }
            $groups[] = [$a];
        }

        return $groups;
    }

    /**
     * The adverts on each file, one per advert number, as plain objects.
     *
     * @param  list<int> $fileIds
     * @return array<int,Collection<int,object>>
     */
    public function advertsOf(int $agencyId, array $fileIds): array
    {
        $out = array_fill_keys($fileIds, collect());
        $keys = $this->fileAddressKeys($agencyId, $fileIds);
        $rows = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', $fileIds)
            ->orderBy('id')
            ->get(['id', 'tracked_property_id as fid', 'canonical_ref', 'portal_ref', 'property_type as ptype', 'bedrooms as beds', 'price', 'property_size_m2 as floor',
                'erf_size_m2 as erf', 'listing_type as ltype', 'address', 'suburb', 'agency_name', 'is_active', 'portal_status', 'created_at']);
        foreach ($rows->groupBy('fid') as $fid => $list) {
            $key = $keys[(int) $fid] ?? null;
            $out[(int) $fid] = $list->each(function ($r) use ($key) {
                $r->ref = (string) ($r->canonical_ref ?: 'id:' . $r->id);
                $r->at_file = $key !== null && self::saysAddress((string) $r->address, $key) ? $key : null;
            })->unique('ref')->values();
        }

        return $out;
    }

    /**
     * "number|street" of each file that has both, lower-cased and space-padded for a whole-word test.
     *
     * @param  list<int> $fileIds
     * @return array<int,string>
     */
    private function fileAddressKeys(int $agencyId, array $fileIds): array
    {
        $out = [];
        foreach (DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('id', $fileIds)->get(['id', 'street_number', 'street_name']) as $f) {
            $n = self::words((string) $f->street_number);
            $st = self::words((string) $f->street_name);
            if ($n !== '' && $st !== '') {
                $out[(int) $f->id] = trim($n) . ' ' . trim($st);
            }
        }

        return $out;
    }

    /** does the advert's own address line carry the file's street number and street name as whole words? ("14 Emtunzi Drive" is not "4 Emtunzi Drive") */
    public static function saysAddress(string $address, string $key): bool
    {
        return $address !== '' && str_contains(self::words($address), ' ' . $key . ' ');
    }

    private static function words(string $t): string
    {
        return ' ' . trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($t))) . ' ';
    }

    /** @param object $a an advert with ptype, beds, price */
    public function describe(object $a): string
    {
        $bits = array_filter([
            isset($a->beds) ? (int) $a->beds . ' bed' : null,
            trim((string) ($a->ptype ?? '')) ?: null,
            (float) ($a->price ?? 0) > 0 ? 'R' . number_format((float) $a->price, 0, '.', ',') : null,
        ]);

        return ($a->portal_ref ?? $a->ref ?? 'advert') . ($bits ? ' (' . implode(', ', $bits) . ')' : '');
    }
}
