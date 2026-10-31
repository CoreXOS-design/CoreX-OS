<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use App\Models\PropertyMatchDecision;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\PropertyIdentityApplier;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\PropertyMatchDecisionService;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Support\Facades\DB;

/**
 * AT-520 step 4.5 (mic-work-tab.md section 4: "80 files hold 5+ adverts with > 2x price spread -> unit-split review"). A property file that holds many
 * adverts with a wide price spread is probably several flats in one block joined onto one file (1 Ocean Club Drive, Zimbali: 33 adverts). This proposes a
 * split - ONE review item per flat-group that would leave the file - and does nothing until an agent presses "Split off as its own property".
 *
 * Grouping: adverts with the same bedrooms, bathrooms and property type whose prices step by no more than the agency's single-property price gap
 * (`portal_group_price_spread`) are one flat. The biggest group stays on the file; every other group is a proposal. A file whose adverts form only ONE
 * group (price changes and re-listings of one home) is not proposed. Accepting creates a new file from the adverts' own public facts (the same builder
 * the 30 Oct split used) and moves those adverts - with their refs, captures and alerts - onto it. Nothing is deleted; "Keep together" is remembered.
 */
final class UnitSplitReview
{
    public function __construct(
        private readonly PropertyMatchDecisionService $decisions,
        private readonly TrackedPropertyMerger $merger,
        private readonly PropertyIdentityApplier $applier,
    ) {
    }

    /** @return array<string,int|string> */
    public function run(int $agencyId, bool $apply): array
    {
        $out = ['files_checked' => 0, 'files_proposed' => 0, 'proposals' => 0, 'skipped' => ''];
        $s = AddressMatchSetting::forAgency($agencyId);
        $minAdverts = (int) $s['unit_split_min_adverts'];
        $spread = (float) $s['unit_split_price_spread'];
        $step = (float) $s['portal_group_price_spread'];

        $files = DB::table('prospecting_listings as l')
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted'])
                    ->where(fn ($w) => $w->whereNull('t.capture_kind')->orWhere('t.capture_kind', '<>', 'deeds_capture'));
            })
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->whereNotNull('l.canonical_ref')->where('l.price', '>', 0)
            ->get(['l.id', 'l.canonical_ref as ref', 'l.price', 'l.bedrooms as beds', 'l.bathrooms as baths', 'l.property_type as ptype', 'l.erf_size_m2 as erf', 'l.first_seen_at', 'l.tracked_property_id as fid'])
            ->groupBy('fid');

        foreach ($files as $fid => $adverts) {
            $adverts = $adverts->unique('ref')->values();
            if ($adverts->count() < $minAdverts) {
                continue;
            }
            $out['files_checked']++;
            $groups = $this->groupsFor($adverts, $spread, $step, (int) $s['unit_split_land_size_gap_pct']);
            if (count($groups) < 2) {
                continue;
            }
            $out['files_proposed']++;
            foreach (array_slice($groups, 1) as $g) {
                if ($this->propose($agencyId, (int) $fid, $g, count($groups), $apply)) {
                    $out['proposals']++;
                }
            }
        }

        return $out;
    }

    /**
     * Everything on a file as flats / stands: adverts of a house or flat are grouped by rooms + type + price steps (only when the file's non-land adverts
     * spread wider than the agency's gap - price changes of one home stay one group); VACANT LAND adverts are grouped by stand size (sizes more than
     * `$landGapPct` percent apart are different stands; a land advert with no size stays where it is). Biggest group first.
     *
     * @param  \Illuminate\Support\Collection<int,object> $adverts
     * @return list<\Illuminate\Support\Collection<int,object>>
     */
    public function groupsFor($adverts, float $spread, float $step, int $landGapPct): array
    {
        $isLand = fn ($a) => preg_match('/land|vacant|stand|plot|erf/i', (string) $a->ptype) === 1;
        $land = $adverts->filter($isLand)->values();
        $built = $adverts->reject($isLand)->values();
        $groups = [];
        if ($built->isNotEmpty()) {
            $wide = $built->max('price') / max(1, $built->min('price')) > $spread;
            $groups = $wide ? $this->groups($built, $step) : [$built];
        }
        $sized = $land->filter(fn ($a) => (float) $a->erf > 0)->sortBy('erf')->values();
        $unsized = $land->reject(fn ($a) => (float) $a->erf > 0)->values();
        $run = collect();
        $landGroups = [];
        foreach ($sized as $a) {
            if ($run->isNotEmpty() && (float) $a->erf / (float) $run->last()->erf > 1 + $landGapPct / 100) {
                $landGroups[] = $run;
                $run = collect();
            }
            $run->push($a);
        }
        if ($run->isNotEmpty()) {
            $landGroups[] = $run;
        }
        if ($unsized->isNotEmpty() && $landGroups !== []) {
            $landGroups[0] = $landGroups[0]->concat($unsized);
        } elseif ($unsized->isNotEmpty()) {
            $landGroups[] = $unsized;
        }
        $all = array_merge($groups, $landGroups);
        usort($all, fn ($x, $y) => [$y->count(), (string) $x->min('first_seen_at')] <=> [$x->count(), (string) $y->min('first_seen_at')]);

        return $all;
    }

    /**
     * Flat-groups, biggest first (ties: the one holding the oldest advert).
     *
     * @param  \Illuminate\Support\Collection<int,object> $adverts
     * @return list<\Illuminate\Support\Collection<int,object>>
     */
    public function groups($adverts, float $step): array
    {
        $groups = [];
        foreach ($adverts->groupBy(fn ($a) => ($a->beds ?? '?') . '|' . ($a->baths ?? '?') . '|' . mb_strtolower(trim((string) $a->ptype))) as $bucket) {
            $run = collect();
            foreach ($bucket->sortBy('price') as $a) {
                if ($run->isNotEmpty() && $a->price / max(1, $run->last()->price) > $step) {
                    $groups[] = $run;
                    $run = collect();
                }
                $run->push($a);
            }
            if ($run->isNotEmpty()) {
                $groups[] = $run;
            }
        }
        usort($groups, fn ($x, $y) => [$y->count(), (string) $x->min('first_seen_at')] <=> [$x->count(), (string) $y->min('first_seen_at')]);

        return $groups;
    }

    private function propose(int $agencyId, int $fid, $group, int $groupCount, bool $apply): bool
    {
        $refs = $group->pluck('ref')->sort()->values()->all();
        $key = 'unit_split:' . $fid . ':' . substr(md5(implode(',', $refs)), 0, 12);
        $exists = PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->where('subject_key', $key)->where('matched_id', $fid)->exists();
        if ($exists) {
            return false;
        }
        $f = $group->first();
        $isLand = preg_match('/land|vacant|stand|plot|erf/i', (string) $f->ptype) === 1;
        $what = $isLand ? trim((string) $f->ptype) . ((float) $f->erf > 0 ? ', ' . number_format((float) $f->erf, 0, '.', ',') . ' m2' : ', size not shown')
            : ($f->beds ?? '?') . ' bed ' . ($f->baths ?? '?') . ' bath ' . trim((string) $f->ptype);
        $label = count($refs) . ' advert' . (count($refs) === 1 ? '' : 's') . ': ' . $what
            . ', R' . number_format((float) $group->min('price'), 0, '.', ',') . ($group->min('price') != $group->max('price') ? ' to R' . number_format((float) $group->max('price'), 0, '.', ',') : '')
            . ' (' . implode(', ', array_slice($refs, 0, 4)) . (count($refs) > 4 ? ', ...' : '') . ')';
        if ($apply) {
            $d = $this->decisions->record($agencyId, PropertyIdentityResolver::DECISION_SUBJECT, $key, 'tracked_property', $fid, 'unit_split',
                'This property holds adverts of ' . $groupCount . ' different-looking flats; this group looks like its own flat.', null,
                ['kind' => 'unit_split', 'label' => $label, 'adverts' => $refs, 'from_tracked_property_id' => $fid], null);
            $this->decisions->recordOutcome($d, 'pending');
        }

        return true;
    }

    /** The agent pressed "Split off as its own property". Returns the message for the screen. */
    public function accept(int $agencyId, PropertyMatchDecision $d): string
    {
        $facts = (array) $d->incoming_facts;
        $orig = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->find((int) $d->matched_id);
        if ($orig === null || $orig->status === TrackedProperty::STATUS_DUPLICATE) {
            throw new \RuntimeException('That property has already been merged into another one; nothing to split.');
        }
        $refs = array_values(array_filter((array) ($facts['adverts'] ?? [])));
        $rows = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $orig->id)->whereNull('deleted_at')->whereIn('canonical_ref', $refs)->get(['id', 'canonical_ref']);
        if ($rows->isEmpty()) {
            throw new \RuntimeException('Those adverts are no longer on this property; press "Keep together" to clear the line.');
        }
        $newId = $this->applier->createSplitFile($orig, $rows->all(), true);
        if ($newId === null) {
            throw new \RuntimeException('The new property could not be created.');
        }
        foreach ($rows->pluck('canonical_ref')->unique() as $ref) {
            $this->merger->moveAdvert($agencyId, (string) $ref, (int) $orig->id, $newId, true, 'unit-split');
        }

        return 'Split: ' . $rows->count() . ' advert' . ($rows->count() === 1 ? '' : 's') . ' now sit on their own property (#' . $newId . '); the original keeps its history.';
    }
}
