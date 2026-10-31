<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Support\Facades\DB;

/**
 * R3 (mic-work-tab.md section 4, property-identity.md 18.1a): P24's own statement that several adverts - often from different agencies - are ONE
 * property. The members present in the agency's Market Intelligence are put on one property file when NOTHING contradicts it (street number, unit,
 * erf, bedrooms, property type, or a price spread above the agency's limit); a member that contradicts the cluster waits on the review list with the
 * contradiction named. Only the grouped ADVERTS move (never a whole file), and an emptied file is flagged duplicate, never deleted. This is the only
 * link across agencies and it uses public facts only: the adverts already belong to this agency's own MIC copy.
 */
final class PortalGroupLinker
{
    public function __construct(private readonly TrackedPropertyMerger $merger, private readonly ReviewPairs $pairs)
    {
    }

    /** @return array<string,int|string> */
    public function run(int $agencyId, bool $apply): array
    {
        $out = ['groups_seen' => 0, 'groups_over_files' => 0, 'adverts_moved' => 0, 'files_emptied' => 0, 'review_pairs' => 0, 'contradictions' => 0, 'skipped' => ''];
        $s = AddressMatchSetting::forAgency($agencyId);
        if (! $s['portal_group_link_enabled']) {
            return ['skipped' => 'switched off'] + $out;
        }
        $autoLink = $s['portal_group_auto_link_min_confidence'] === 'high';
        $spread = (float) $s['portal_group_price_spread'];

        $rows = DB::table('p24_group_members as m')
            ->join('prospecting_listings as l', function ($j) use ($agencyId) {
                $j->whereRaw("l.canonical_ref = CONCAT('p24:', m.listing_number)")->where('l.agency_id', $agencyId)->where('l.portal_source', 'p24')->whereNull('l.deleted_at');
            })
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted']);
            })
            ->get(['m.p24_group_id as gid', 'l.canonical_ref as ref', 't.id as fid', 'l.bedrooms as beds', 'l.property_type as ptype', 'l.price',
                't.street_number', 't.street_name', 't.suburb', 't.unit_number', 't.erf_number', 't.complex_name']);

        foreach ($rows->groupBy('gid') as $members) {
            $out['groups_seen']++;
            $files = $members->groupBy('fid');
            if ($files->count() < 2) {
                continue;
            }
            $out['groups_over_files']++;
            // best-known address first, then the older file: the anchor the others must agree with
            $ordered = $files->sortBy(fn ($m, $fid) => [-$this->rank($m->first()), (int) $fid])->keys()->map(fn ($k) => (int) $k)->all();
            $cluster = [array_shift($ordered)];
            foreach ($ordered as $fid) {
                $why = $this->contradiction($files, $cluster, $fid, $spread);
                if ($why === null) {
                    $cluster[] = $fid;
                    continue;
                }
                $out['contradictions']++;
                if ($this->pairs->queue($agencyId, $cluster[0], $fid, 'portal_group', 'Property24 groups these adverts as one property, but ' . $why . '.', null, $apply)) {
                    $out['review_pairs']++;
                }
            }
            $anchor = $cluster[0];
            foreach (array_slice($cluster, 1) as $fid) {
                if (! $autoLink) {
                    if ($this->pairs->queue($agencyId, $anchor, $fid, 'portal_group', 'Property24 groups these adverts as one property (automatic joining is off).', null, $apply)) {
                        $out['review_pairs']++;
                    }
                    continue;
                }
                foreach ($files[$fid]->pluck('ref')->unique() as $ref) {
                    $this->merger->moveAdvert($agencyId, (string) $ref, $fid, $anchor, $apply, 'portal-group');
                    $out['adverts_moved']++;
                    if ($apply) {
                        DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->where('tracked_property_id', $anchor)->where('canonical_ref', $ref)
                            ->update(['match_confidence' => 'high', 'match_method' => 'portal_group', 'matched_at' => now()]);
                    }
                }
                if ($apply && $this->merger->isEmptyShell($fid)) {
                    $this->merger->flagDuplicate($fid, $anchor, 'portal-group');
                    $out['files_emptied']++;
                }
            }
        }

        return $out;
    }

    private function rank(object $m): int
    {
        if (trim((string) $m->street_name) !== '' && trim((string) $m->street_number) !== '') {
            return 3;
        }

        return trim((string) $m->street_name) !== '' || trim((string) $m->complex_name) !== '' || trim((string) $m->erf_number) !== '' ? 2 : (trim((string) $m->suburb) !== '' ? 1 : 0);
    }

    /** the first thing that stops $fid joining $cluster, or null */
    private function contradiction($files, array $cluster, int $fid, float $spread): ?string
    {
        $b = $files[$fid];
        foreach ($cluster as $cid) {
            $a = $files[$cid];
            foreach ([['street_number', 'street number'], ['unit_number', 'unit'], ['erf_number', 'erf number']] as [$col, $label]) {
                $x = mb_strtolower(trim((string) $a->first()->{$col}));
                $y = mb_strtolower(trim((string) $b->first()->{$col}));
                if ($x !== '' && $y !== '' && $x !== $y) {
                    return "the {$label} differs ({$x} and {$y})";
                }
            }
            $ba = $a->pluck('beds')->filter(fn ($v) => $v !== null)->unique();
            $bb = $b->pluck('beds')->filter(fn ($v) => $v !== null)->unique();
            if ($ba->isNotEmpty() && $bb->isNotEmpty() && $ba->intersect($bb)->isEmpty()) {
                return 'the bedrooms differ (' . $ba->first() . ' and ' . $bb->first() . ')';
            }
            $ta = mb_strtolower(trim((string) $a->pluck('ptype')->filter()->first()));
            $tb = mb_strtolower(trim((string) $b->pluck('ptype')->filter()->first()));
            if ($ta !== '' && $tb !== '' && $ta !== $tb) {
                return "the property type differs ({$ta} and {$tb})";
            }
        }
        $prices = collect($cluster)->push($fid)->flatMap(fn ($id) => $files[$id]->pluck('price'))->filter(fn ($p) => $p > 0);
        if ($prices->count() >= 2 && $prices->min() > 0 && $prices->max() / $prices->min() > $spread) {
            return 'the prices are ' . round($prices->max() / $prices->min(), 1) . ' times apart (limit ' . $spread . ')';
        }

        return null;
    }
}
