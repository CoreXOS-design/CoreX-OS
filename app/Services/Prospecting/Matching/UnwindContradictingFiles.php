<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\PropertyIdentityApplier;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Support\Facades\DB;

/**
 * AT-520 4.13: undo what was over-merged. For every file of the agency that holds 2+ adverts, the adverts are split into groups that fit together
 * (MergeGuard - the same tests the automatic rules now run before they move an advert). One group stays on the file - the one with a live advert, then
 * the biggest; every other group goes back to its own file: the pre-merge file when one was flagged duplicate of this file with the advert's own
 * creation time (restore), otherwise a new file made from the adverts' public facts. Claims, notes, contacts and deeds stay on the file; nothing is
 * deleted; every change is journalled through the merger. A dry run (the default) only reports.
 */
final class UnwindContradictingFiles
{
    public function __construct(
        private readonly MergeGuard $guard,
        private readonly TrackedPropertyMerger $merger,
        private readonly PropertyIdentityApplier $applier,
    ) {
    }

    /** @return array<string,mixed> summary + 'rows' (the full per-file report) */
    public function run(int $agencyId, bool $apply): array
    {
        $out = ['files_checked' => 0, 'files_touched' => 0, 'adverts_moved' => 0, 'groups_moved' => 0, 'to_restored_file' => 0, 'to_new_file' => 0,
            'by_reason' => ['type' => 0, 'bedrooms' => 0, 'price' => 0, 'size' => 0], 'rows' => []];

        $ids = DB::table('prospecting_listings as l')
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->where('t.status', TrackedProperty::STATUS_ACTIVE)
                    ->where(fn ($w) => $w->whereNull('t.capture_kind')->orWhere('t.capture_kind', '<>', 'deeds_capture'));
            })
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->whereNotNull('l.tracked_property_id')
            ->groupBy('l.tracked_property_id')->havingRaw('COUNT(DISTINCT COALESCE(l.canonical_ref, l.id)) >= 2')->orderBy('l.tracked_property_id')->pluck('l.tracked_property_id')
            ->map(fn ($i) => (int) $i)->all();

        foreach (array_chunk($ids, 400) as $chunk) {
            $adverts = $this->guard->advertsOf($agencyId, $chunk);
            $files = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->whereIn('id', $chunk)->get()->keyBy('id');
            foreach ($chunk as $fid) {
                $out['files_checked']++;
                $list = $adverts[$fid]->sortBy([fn ($a, $b) => (int) $b->is_active <=> (int) $a->is_active, fn ($a, $b) => $a->id <=> $b->id])->values();
                $groups = $this->guard->partition($agencyId, $list);
                if (count($groups) < 2) {
                    continue;
                }
                $file = $files[$fid];
                $atFile = fn (array $g) => collect($g)->filter(fn ($a) => $a->at_file !== null)->count();
                usort($groups, fn ($x, $y) => [-$atFile($x), -collect($x)->sum('is_active'), -count($x), $x[0]->id] <=> [-$atFile($y), -collect($y)->sum('is_active'), -count($y), $y[0]->id]);
                $stay = array_shift($groups);
                $row = ['file' => $fid, 'address' => $this->addressOf($file), 'kept' => $this->refs($stay), 'moved' => []];
                $usedRestore = [];
                foreach ($groups as $g) {
                    $why = $this->guard->conflictBetween($agencyId, collect($g), collect($stay)) ?? 'does not fit';
                    $reason = $this->reasonKind($why);
                    $out['by_reason'][$reason]++;
                    $restore = $this->restoreTarget($agencyId, $fid, $g, $usedRestore);
                    $newId = null;
                    if ($apply) {
                        $newId = $restore ?? $this->applier->createSplitFile($file, array_map(fn ($a) => (object) ['id' => $a->id], $g), true);
                        if ($restore !== null) {
                            DB::table('tracked_properties')->where('id', $restore)->update(['status' => TrackedProperty::STATUS_ACTIVE, 'duplicate_of_tracked_property_id' => null, 'updated_at' => now()]);
                            $this->merger->log('unwind', 'tracked_properties', $restore, 'status', TrackedProperty::STATUS_DUPLICATE, TrackedProperty::STATUS_ACTIVE);
                        }
                        foreach ($g as $a) {
                            $this->merger->moveAdvert($agencyId, (string) ($a->canonical_ref ?: $a->ref), $fid, (int) $newId, true, 'unwind');
                        }
                    }
                    $restore !== null ? $out['to_restored_file']++ : $out['to_new_file']++;
                    $out['groups_moved']++;
                    $out['adverts_moved'] += count($g);
                    $row['moved'][] = ['adverts' => $this->refs($g), 'to' => $restore !== null ? 'restored file #' . $restore : ($apply ? 'new file #' . $newId : 'a new file'),
                        'reason' => $why, 'reason_kind' => $reason];
                }
                $out['files_touched']++;
                $out['rows'][] = $row;
            }
        }

        return $out;
    }

    /** the pre-merge file of this group: flagged duplicate of $fid, empty now, created at the same moment as one of the group's adverts */
    private function restoreTarget(int $agencyId, int $fid, array $group, array &$used): ?int
    {
        foreach ($group as $a) {
            $d = DB::table('tracked_properties')->where('agency_id', $agencyId)->where('status', TrackedProperty::STATUS_DUPLICATE)->where('duplicate_of_tracked_property_id', $fid)
                ->whereNull('deleted_at')->where('created_at', $a->created_at)->whereNotIn('id', $used)->orderBy('id')->value('id');
            if ($d && ! DB::table('prospecting_listings')->where('tracked_property_id', $d)->whereNull('deleted_at')->exists()) {
                $used[] = (int) $d;

                return (int) $d;
            }
        }

        return null;
    }

    private function reasonKind(string $why): string
    {
        return match (true) {
            str_contains($why, 'property types') => 'type',
            str_contains($why, 'bedrooms') => 'bedrooms',
            str_contains($why, 'prices') => 'price',
            default => 'size',
        };
    }

    /** @param array<int,object> $group @return list<string> */
    private function refs(array $group): array
    {
        return array_values(array_map(fn ($a) => (string) ($a->portal_ref ?: $a->ref), $group));
    }

    private function addressOf(TrackedProperty $f): string
    {
        $line = trim(trim((string) $f->unit_number . ' ' . $f->street_number . ' ' . $f->street_name) . ', ' . $f->suburb, ' ,');

        return $line !== '' ? $line : (string) ($f->address_raw ?: '(no address)');
    }
}
