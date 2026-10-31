<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\SameAddressSweep;
use Illuminate\Support\Facades\DB;

/**
 * AT-520 4.8 (mic-work-tab.md 11.3.J): which pending Possible-matches pairs should raise a "may be the same property" chip on a MIC row. Only the three
 * kinds that say two records are probably ONE property: a P24 group (`portal_group`), the same agency on both portals (`twin`) and a same-address freehold
 * pair (`same_address` that is not a flat / complex with no unit number and not a block of many files). Flats, unit-splits, complexes, advert clashes and
 * own-listing candidates never raise it. The chip links to `corex.possible-matches.index?file=<id>`. Agency-bound.
 */
final class SamePropertyChips
{
    /** @var list<string> */
    public const KINDS = ['portal_group', 'twin', 'same_address'];

    /** @return array<int,int> property file id => number of waiting same-property pairs it is in */
    public function counts(int $agencyId): array
    {
        $rows = DB::table('property_match_decisions as d')->where('d.agency_id', $agencyId)->where('d.subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->whereNull('d.deleted_at')->whereNull('d.confirmed_at')->whereNull('d.rejected_at')->where('d.subject_key', 'not like', 'property:%')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(d.incoming_facts, '$.kind')) IN ('" . implode("','", self::KINDS) . "')")
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(d.incoming_facts, '$.kind')) <> 'same_address'")
                    ->orWhere(fn ($w) => $w->where('d.reason', 'not like', SameAddressSweep::REASON_FLAT . '%')->where('d.reason', 'not like', SameAddressSweep::REASON_BLOCK . '%'));
            })
            ->get(['d.matched_id', DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(d.incoming_facts, '$.incoming_tracked_property_id')) AS UNSIGNED) as other")]);

        $out = [];
        foreach ($rows as $r) {
            foreach ([(int) $r->matched_id, (int) $r->other] as $id) {
                if ($id > 0) {
                    $out[$id] = ($out[$id] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    /** the chip for one file, or null */
    public function forFile(int $agencyId, int $fileId): ?array
    {
        $n = $this->counts($agencyId)[$fileId] ?? 0;

        return $n > 0 ? ['count' => $n, 'url' => route('corex.possible-matches.index', ['file' => $fileId]), 'label' => 'May be the same property'] : null;
    }
}
