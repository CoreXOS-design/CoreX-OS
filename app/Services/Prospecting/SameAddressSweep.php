<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\AddressMatchSetting;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\Matching\AdvertEvidence;
use Illuminate\Support\Facades\DB;

/**
 * Same-address sweep (property-identity.md section 17, M2-M4): two property files of ONE agency that carry the same street number, street
 * and unit (and the same suburb, or no suburb on any of them) are one house. Houses and stands merge through TrackedPropertyMerger (the loser is
 * flagged duplicate, never deleted). A flat or scheme unit with no unit number, or a block with many files, is NOT merged (Johan, 31 Oct): the
 * pair goes to the Possible matches review list instead. A pair the agent already answered "not the same" is never touched again.
 *
 * Scopes: `no-suburb` (files with no suburb - M2), `exact` (a suburb on every file - M3 and the after-import sweep, M4), `all`.
 * Deeds-capture files are left to the Deeds Capture screen.
 */
final class SameAddressSweep
{
    public const SCOPES = ['no-suburb', 'exact', 'all'];
    public const MAX_AUTO_MERGE_FILES = 4;
    private const UNIT_TYPE = '/apartment|flat|townhouse|duplex|simplex|cluster|studio|penthouse|unit|sectional/i';

    public function __construct(
        private readonly TrackedPropertyMerger $merger,
        private readonly PropertyMatchDecisionService $decisions,
        private readonly AdvertEvidence $evidence,
    ) {
    }

    /**
     * @return array{scope:string, apply:bool, groups:int, files:int, merged_files:int, merge_refused:int, review_pairs:int, vetoed:int, flat_groups:int, big_groups:int, contradictions:int, actions:list<array<string,mixed>>}
     */
    public function run(int $agencyId, string $scope, bool $apply, string $step = 'same-address'): array
    {
        $out = ['scope' => $scope, 'apply' => $apply, 'groups' => 0, 'files' => 0, 'merged_files' => 0, 'merge_refused' => 0, 'review_pairs' => 0, 'vetoed' => 0, 'flat_groups' => 0, 'big_groups' => 0, 'contradictions' => 0, 'actions' => []];

        $spread = (float) AddressMatchSetting::forAgency($agencyId)['portal_group_price_spread'];
        foreach ($this->groups($agencyId, $scope) as $ids) {
            $out['groups']++;
            $out['files'] += count($ids);
            $tps = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->whereIn('id', $ids)->orderBy('id')->get()->all();
            if (count($tps) < 2) {
                continue;
            }
            $unit = trim((string) ($tps[0]->unit_number ?? ''));
            $flat = $unit === '' && $this->anyUnitType($tps);
            if ($flat || count($tps) > self::MAX_AUTO_MERGE_FILES) {
                $out[$flat ? 'flat_groups' : 'big_groups']++;
                foreach (array_slice($tps, 1) as $other) {
                    $this->review($agencyId, $tps[0], $other, $flat ? 'Same address, but a flat or complex with no unit number - may be a different unit.' : 'Same address on many files - check which are the same property.', $apply, $out);
                }
                continue;
            }

            $win = $tps[0];
            foreach (array_slice($tps, 1) as $other) {
                if ($this->vetoed($agencyId, $win, $other)) {
                    $out['vetoed']++;
                    continue;
                }
                if (($why = $this->evidence->contradiction($agencyId, (int) $win->id, (int) $other->id, $spread)) !== null) {
                    $out['contradictions']++;
                    $this->review($agencyId, $win, $other, 'Same address, but ' . $why . ' - may be two different properties.', $apply, $out);
                    continue;
                }
                [$w, $l] = $this->merger->chooseWinner($win, $other);
                $res = $this->merger->merge($w, $l, $apply, $step);
                if ($res['merged']) {
                    $out['merged_files']++;
                    $out['actions'][] = ['action' => 'merge', 'winner' => (int) $w->id, 'loser' => (int) $l->id];
                    $win = $w;
                } else {
                    $out['merge_refused']++;
                    $out['actions'][] = ['action' => 'refused', 'files' => [(int) $w->id, (int) $l->id], 'reason' => $res['reason']];
                    $this->review($agencyId, $win, $other, 'Same address, but the merge was refused: ' . (string) $res['reason'], $apply, $out);
                }
            }
        }

        return $out;
    }

    /** @return list<list<int>> file ids per same-address group (2 or more files) */
    public function groups(int $agencyId, string $scope): array
    {
        $rows = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('status', ['active', 'promoted'])
            ->where(fn ($q) => $q->whereNull('capture_kind')->orWhere('capture_kind', '<>', 'deeds_capture'))
            ->whereRaw("TRIM(COALESCE(street_name,'')) <> ''")->whereRaw("TRIM(COALESCE(street_number,'')) <> ''")
            ->get(['id', 'street_number', 'street_name', 'unit_number', 'suburb', 'suburb_normalised']);

        $buckets = [];
        foreach ($rows as $r) {
            $sub = trim((string) ($r->suburb_normalised ?: mb_strtolower(trim((string) $r->suburb))));
            if (($scope === 'no-suburb' && $sub !== '') || ($scope === 'exact' && $sub === '')) {
                continue;
            }
            $key = mb_strtolower(trim((string) $r->street_number)) . '|' . preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $r->street_name)))
                . '|' . $sub . '|' . mb_strtolower(trim((string) ($r->unit_number ?? '')));
            $buckets[$key][] = (int) $r->id;
        }

        return array_values(array_filter($buckets, fn (array $ids) => count($ids) >= 2));
    }

    /** @param list<TrackedProperty> $tps */
    private function anyUnitType(array $tps): bool
    {
        foreach ($tps as $t) {
            if (preg_match(self::UNIT_TYPE, (string) $t->property_type) || trim((string) ($t->scheme_name ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function subjectKey(TrackedProperty $first): string
    {
        return 'same_address:' . $first->id;
    }

    private function vetoed(int $agencyId, TrackedProperty $a, TrackedProperty $b): bool
    {
        $type = PropertyIdentityResolver::DECISION_SUBJECT;

        return $this->decisions->isRejected($agencyId, $type, $this->subjectKey($a), 'tracked_property', (int) $b->id)
            || $this->decisions->isRejected($agencyId, $type, $this->subjectKey($b), 'tracked_property', (int) $a->id);
    }

    /** @param array<string,mixed> $out */
    private function review(int $agencyId, TrackedProperty $first, TrackedProperty $other, string $reason, bool $apply, array &$out): void
    {
        $type = PropertyIdentityResolver::DECISION_SUBJECT;
        $key = $this->subjectKey($first);
        if ($this->vetoed($agencyId, $first, $other)) {
            $out['vetoed']++;

            return;
        }
        $pairExists = \App\Models\PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)->where('subject_type', $type)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('matched_id', $other->id)->where('incoming_facts->incoming_tracked_property_id', (int) $first->id))
                ->orWhere(fn ($w) => $w->where('matched_id', $first->id)->where('incoming_facts->incoming_tracked_property_id', (int) $other->id)))
            ->whereNull('rejected_at')->exists();
        if ($pairExists) {
            return;
        }
        if ($apply) {
            $d = $this->decisions->record($agencyId, $type, $key, 'tracked_property', (int) $other->id, 'address_unit', mb_substr($reason, 0, 480), null,
                ['kind' => 'same_address', 'incoming_tracked_property_id' => (int) $first->id], null);
            $this->decisions->recordOutcome($d, 'pending');
        }
        $out['review_pairs']++;
        $out['actions'][] = ['action' => 'review', 'files' => [(int) $first->id, (int) $other->id], 'reason' => $reason];
    }
}
