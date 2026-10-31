<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use App\Models\PropertyMatchDecision;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\SameAddressSweep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * AT-520 4.10 (property-identity.md 17.10): keeps the Possible matches list down to the genuinely uncertain pairs. Johan's standing principle (31 Oct):
 * "Rather UNDER-match than OVER-match" - so this rule only CLOSES or GROUPS questions. It NEVER merges or links files.
 *
 *  1. moot            a question whose answer already exists: our listing already joined to that file, or an advert that has since moved off both files.
 *  2. one per listing our listing with several candidate files keeps its best candidate; the others are closed as "superseded" (the kept question lists them).
 *  3. flat in complex two flats at one address with no unit number are different flats unless their own adverts agree on bedrooms and floor size: closed as
 *                     "not the same, grouped under the complex" and both files are filed under one complex (never merged).
 *
 * Every closed question is written to a journal (decision id, rule, what was done) so it can be reversed. Closing is soft (deleted_at) except rule 3, which
 * is the agent-style "not the same" answer (rejected_at, no user) so the pair is not asked again.
 */
final class BacklogCollapse
{
    public const CODE_COMPLEX = 'auto_grouped_complex';
    private const UNIT_TYPE = '/apartment|flat|townhouse|duplex|simplex|cluster|studio|penthouse|unit|sectional/i';

    /** @return array<string,int|string> */
    public function run(int $agencyId, bool $apply, ?string $journalPath = null): array
    {
        $out = ['moot_linked' => 0, 'moot_moved' => 0, 'superseded' => 0, 'kept_listings' => 0, 'flats_grouped' => 0, 'flats_kept_open' => 0, 'complexes_created' => 0, 'skipped' => ''];
        if (! AddressMatchSetting::forAgency($agencyId)['auto_tidy']) {
            return ['skipped' => 'switched off'] + $out;
        }
        $fh = null;
        if ($apply) {
            $journalPath ??= storage_path('app/reports/backlog-collapse-' . now()->format('Ymd') . '.jsonl');
            File::ensureDirectoryExists(dirname($journalPath));
            $fh = fopen($journalPath, 'ab');
        }
        $log = function (PropertyMatchDecision $d, string $rule, array $extra = []) use ($fh) {
            if ($fh) {
                fwrite($fh, json_encode(['at' => now()->toIso8601String(), 'decision_id' => (int) $d->id, 'rule' => $rule, 'subject_key' => $d->subject_key, 'matched_id' => $d->matched_id] + $extra, JSON_UNESCAPED_UNICODE) . "\n");
            }
        };

        $pending = fn () => PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->whereNull('confirmed_at')->whereNull('rejected_at');

        // 1 + 2: our listings (subject_key "property:<id>")
        $byListing = [];
        foreach ($pending()->where('subject_key', 'like', 'property:%')->orderBy('id')->get() as $d) {
            $byListing[$d->subject_key][] = $d;
        }
        foreach ($byListing as $key => $rows) {
            $propertyId = (int) substr($key, 9);
            $linked = (int) DB::table('properties')->where('id', $propertyId)->where('agency_id', $agencyId)->value('tracked_property_id');
            $open = [];
            foreach ($rows as $d) {
                if ($linked > 0 && (int) $d->matched_id === $linked) {
                    $out['moot_linked']++;
                    $this->close($d, 'auto_already_linked', $apply);
                    $log($d, 'moot-linked');
                } else {
                    $open[] = $d;
                }
            }
            if (count($open) < 2) {
                continue;
            }
            usort($open, fn ($a, $b) => [-(int) ($a->confidence_score ?? -1), $a->id] <=> [-(int) ($b->confidence_score ?? -1), $b->id]);
            $keep = array_shift($open);
            $out['kept_listings']++;
            if ($apply) {
                $facts = (array) ($keep->incoming_facts ?? []);
                $facts['also_considered'] = array_map(fn ($o) => (int) $o->matched_id, $open);
                $keep->forceFill(['incoming_facts' => $facts])->save();
            }
            foreach ($open as $d) {
                $out['superseded']++;
                $this->close($d, 'auto_superseded', $apply);
                $log($d, 'one-per-listing', ['kept_decision_id' => (int) $keep->id]);
            }
        }

        // 1: a clash whose advert has since moved off both files is moot
        foreach ($pending()->where('incoming_facts->kind', 'advert_clash')->orderBy('id')->get() as $d) {
            $other = (int) ($d->incoming_facts['incoming_tracked_property_id'] ?? 0);
            $on = (int) DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('canonical_ref', $d->subject_key)->value('tracked_property_id');
            if ($on > 0 && $on !== (int) $d->matched_id && $on !== $other) {
                $out['moot_moved']++;
                $this->close($d, 'auto_advert_moved', $apply);
                $log($d, 'moot-moved', ['advert_now_on' => $on]);
            }
        }

        // 3: flats at one address with no unit number
        $flats = $pending()->whereIn('incoming_facts->kind', ['same_address', 'capture'])->orWhere(fn ($q) => $q->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->whereNull('confirmed_at')->whereNull('rejected_at')->whereNull('incoming_facts->kind')->where('subject_key', 'not like', 'property:%'))->orderBy('id')->get();
        foreach ($flats as $d) {
            if (! str_contains((string) $d->reason, 'no unit number') && ! str_starts_with((string) $d->reason, SameAddressSweep::REASON_FLAT)) {
                continue;
            }
            $firstId = (int) ($d->incoming_facts['incoming_tracked_property_id'] ?? 0);
            $otherId = (int) $d->matched_id;
            if ($firstId <= 0 || $otherId <= 0 || $firstId === $otherId) {
                continue;
            }
            $files = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('id', [$firstId, $otherId])->get()->keyBy('id');
            if ($files->count() < 2 || ! $files->every(fn ($f) => preg_match(self::UNIT_TYPE, (string) $f->property_type) || trim((string) ($f->scheme_name ?? '')) !== '')) {
                continue; // a house, or a file we cannot read as a flat: stays a question
            }
            if ($this->likelySameFlat($agencyId, $firstId, $otherId)) {
                $out['flats_kept_open']++;
                continue;
            }
            $out['flats_grouped']++;
            if ($apply) {
                $out['complexes_created'] += $this->groupUnderComplex($agencyId, $files[$firstId], $files[$otherId]);
                $d->forceFill(['rejected_at' => now(), 'rejected_by_user_id' => null, 'reject_reason_code' => self::CODE_COMPLEX, 'outcome' => 'grouped_under_complex',
                    'rejected_reason' => 'Different flats in one complex: grouped under the complex, not merged.'])->save();
            }
            $log($d, 'flat-in-complex', ['files' => [$firstId, $otherId]]);
        }
        if ($fh) {
            fclose($fh);
        }

        return $out;
    }

    private function close(PropertyMatchDecision $d, string $outcome, bool $apply): void
    {
        if ($apply) {
            $d->forceFill(['outcome' => $outcome])->save();
            $d->delete();
        }
    }

    /** two flats whose own adverts agree on bedrooms AND floor size are likely one flat advertised twice: that stays a question (and the twin rule) */
    private function likelySameFlat(int $agencyId, int $a, int $b): bool
    {
        $facts = function (int $id) use ($agencyId) {
            $r = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $id)->whereNull('deleted_at')
                ->selectRaw('COUNT(DISTINCT bedrooms) as nb, MIN(bedrooms) as beds, COUNT(DISTINCT property_size_m2) as ns, MIN(property_size_m2) as size')->first();

            return ($r && $r->nb == 1 && $r->ns == 1 && $r->beds !== null && $r->size !== null) ? [(int) $r->beds, (int) $r->size] : null;
        };
        $fa = $facts($a);

        return $fa !== null && $fa === $facts($b);
    }

    /** files both flats under one complex row named after their shared address; returns 1 when a complex was created */
    private function groupUnderComplex(int $agencyId, object $a, object $b): int
    {
        $existing = $a->complex_id ?: $b->complex_id;
        $created = 0;
        if (! $existing) {
            $name = trim(trim((string) $a->street_number) . ' ' . trim((string) $a->street_name));
            $suburb = trim((string) $a->suburb);
            $existing = DB::table('property_complexes')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('name', $name)->where('suburb_name', $suburb)->value('id');
            if (! $existing) {
                $existing = DB::table('property_complexes')->insertGetId(['agency_id' => $agencyId, 'name' => $name, 'suburb_name' => $suburb, 'property_type' => $a->property_type, 'land_size_m2' => 0, 'units_count' => 2,
                    'created_at' => now(), 'updated_at' => now()]);
                $created = 1;
            }
        }
        DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('id', [$a->id, $b->id])->whereNull('complex_id')->update(['complex_id' => $existing]);

        return $created;
    }
}
