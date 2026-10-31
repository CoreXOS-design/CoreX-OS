<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Support\Facades\DB;

/**
 * R4 (mic-work-tab.md section 4, property-identity.md 18.1): the SAME agency advertising one property on both portals - an advert with no address on
 * one, an advert with an address on the other. Never across agencies (the advertiser name must be the same); required: same suburb (by id), type,
 * bedrooms, bathrooms, price within the agency's tolerance, garages and sizes agreeing where both are known. Review-only unless the agency has switched
 * automatic joining on AND the pair has enough corroboration (same agent, same wording fingerprint, same first-photo fingerprint, same size) AND it is
 * the only candidate.
 */
final class SameAgencyTwinMatcher
{
    public function __construct(private readonly TrackedPropertyMerger $merger, private readonly ReviewPairs $pairs)
    {
    }

    /** @return array<string,int|string> */
    public function run(int $agencyId, bool $apply): array
    {
        $out = ['no_address_adverts' => 0, 'with_twin' => 0, 'linked' => 0, 'review_pairs' => 0, 'skipped' => ''];
        $s = AddressMatchSetting::forAgency($agencyId);
        if (! $s['twin_match_enabled']) {
            return ['skipped' => 'switched off'] + $out;
        }
        $tol = $s['twin_price_tolerance_pct'] / 100;

        $rows = DB::table('prospecting_listings as l')
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted']);
            })
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.is_active', 1)->where('l.price', '>', 0)
            ->whereNotNull('l.bedrooms')->whereNotNull('l.bathrooms')->whereNotNull('l.p24_suburb_id')
            ->whereRaw("TRIM(COALESCE(l.agency_name,'')) <> ''")->whereRaw("TRIM(COALESCE(l.property_type,'')) <> ''")
            ->get(['l.id', 'l.canonical_ref as ref', 'l.portal_source as portal', 'l.agency_name', 'l.p24_suburb_id as sub', 'l.property_type as ptype', 'l.bedrooms as beds',
                'l.bathrooms as baths', 'l.garages', 'l.price', 'l.property_size_m2 as floor', 'l.erf_size_m2 as erf', 'l.agent_portal_id as agent', 'l.wording_fingerprint as wording',
                'l.first_photo_fingerprint as photo', 'l.tracked_property_id as fid', 'l.address', 't.street_name']);

        $key = fn ($r) => mb_strtolower(trim((string) $r->agency_name)) . '|' . $r->sub . '|' . mb_strtolower(trim((string) $r->ptype)) . '|' . $r->beds . '|' . $r->baths;
        $hasAddress = fn ($r) => trim((string) $r->address) !== '' || trim((string) $r->street_name) !== '';
        $addressed = $rows->filter($hasAddress)->groupBy($key);

        foreach ($rows->reject($hasAddress) as $a) {
            $out['no_address_adverts']++;
            $cands = collect($addressed[$key($a)] ?? [])->filter(fn ($b) => $b->portal !== $a->portal && (int) $b->fid !== (int) $a->fid
                && abs($a->price - $b->price) / $b->price <= $tol
                && ($a->garages === null || $b->garages === null || $a->garages == $b->garages)
                && ($a->floor === null || $b->floor === null || (float) $a->floor == (float) $b->floor)
                && ($a->erf === null || $b->erf === null || (float) $a->erf == (float) $b->erf));
            if ($cands->isEmpty()) {
                continue;
            }
            $out['with_twin']++;
            if ($s['twin_auto_link'] && $cands->count() === 1 && $this->corroboration($a, $cands->first()) >= $s['twin_min_corroboration']) {
                $b = $cands->first();
                $this->merger->moveAdvert($agencyId, (string) $a->ref, (int) $a->fid, (int) $b->fid, $apply, 'twin');
                if ($apply) {
                    DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->where('tracked_property_id', $b->fid)->where('canonical_ref', $a->ref)
                        ->update(['match_confidence' => 'high', 'match_method' => 'twin_portal', 'matched_at' => now()]);
                    if ($this->merger->isEmptyShell((int) $a->fid)) {
                        $this->merger->flagDuplicate((int) $a->fid, (int) $b->fid, 'twin');
                    }
                }
                $out['linked']++;
                continue;
            }
            foreach ($cands->take(3) as $b) {
                $n = $this->corroboration($a, $b);
                $why = 'The same agency (' . trim((string) $a->agency_name) . ') has this ' . strtoupper((string) $a->portal) . ' advert without an address and a ' . strtoupper((string) $b->portal)
                    . ' advert with one: same suburb, type, bedrooms and bathrooms, price within ' . $s['twin_price_tolerance_pct'] . '%; ' . $n . ' extra sign' . ($n === 1 ? '' : 's') . ' agree.';
                if ($this->pairs->queue($agencyId, (int) $b->fid, (int) $a->fid, 'twin', $why, $n, $apply, ['advert_a' => $a->ref, 'advert_b' => $b->ref])) {
                    $out['review_pairs']++;
                }
            }
        }

        return $out;
    }

    private function corroboration(object $a, object $b): int
    {
        $n = 0;
        $n += ($a->agent !== null && $a->agent === $b->agent) ? 1 : 0;
        $n += ($a->wording !== null && $a->wording === $b->wording) ? 1 : 0;
        $n += ($a->photo !== null && $a->photo === $b->photo) ? 1 : 0;
        $n += (($a->floor !== null && $b->floor !== null && (float) $a->floor == (float) $b->floor) || ($a->erf !== null && $b->erf !== null && (float) $a->erf == (float) $b->erf)) ? 1 : 0;

        return $n;
    }
}
