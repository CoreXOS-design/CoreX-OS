<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use Illuminate\Support\Facades\DB;

/**
 * mic-work-tab.md 13.12: the memory behind "Not the same property". Every automatic rule that puts an advert on a file or folds two files together asks it
 * first (through MergeGuard / ReviewPairs / TrackedPropertyMerger::merge):
 *   - an advert that was split off a file is never auto-joined to that file again (by its canonical ref),
 *   - an advert that sits on the file it was split from is never auto-joined to the split-off file either (the other direction),
 *   - the split-off file and the file it came from are never auto-merged.
 * A file that was itself folded into another ("duplicate of") counts as that other file. Undone splits (`undone_at`) no longer apply. The agency's rows are read
 * once per half minute per process; splitting or undoing flushes it.
 */
final class MatchExclusions
{
    /** @var array<int,array{at:int,rows:list<object>}> */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** @return list<object> */
    public function active(int $agencyId): array
    {
        $m = self::$memo[$agencyId] ?? null;
        if ($m !== null && $m['at'] >= time() - 30) {
            return $m['rows'];
        }
        $rows = DB::table('mic_match_exclusions')->where('agency_id', $agencyId)->whereNull('undone_at')
            ->get(['id', 'file_id', 'new_file_id', 'canonical_ref', 'portal_ref', 'created_by', 'created_at'])->all();
        self::$memo[$agencyId] = ['at' => time(), 'rows' => $rows];

        return $rows;
    }

    /** the file and every file that was folded into it */
    private function family(int $agencyId, int $fileId): array
    {
        $ids = [$fileId];
        for ($i = 0; $i < 3; $i++) {
            $more = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('duplicate_of_tracked_property_id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $new = array_values(array_diff($more, $ids));
            if (! $new) {
                break;
            }
            $ids = array_merge($ids, $new);
        }

        return $ids;
    }

    /** why this set of adverts (canonical refs) may not join $fileId, or null */
    public function advertConflict(int $agencyId, int $fileId, array $refs): ?string
    {
        $rows = $this->active($agencyId);
        if (! $rows) {
            return null;
        }
        $refs = array_values(array_filter(array_map('strval', $refs), fn ($r) => $r !== '' && ! str_starts_with($r, '(')));
        $fam = $this->family($agencyId, $fileId);
        foreach ($rows as $e) {
            if ($e->canonical_ref !== null && in_array($e->canonical_ref, $refs, true) && in_array((int) $e->file_id, $fam, true)) {
                return 'an agent marked advert ' . ($e->portal_ref ?: $e->canonical_ref) . ' as not the same property as this one';
            }
            if (in_array((int) $e->new_file_id, $fam, true) && $refs) {
                $from = $this->family($agencyId, (int) $e->file_id);
                $cur = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereIn('canonical_ref', $refs)->whereNull('deleted_at')->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
                if (array_intersect($cur, $from)) {
                    return 'an agent split advert ' . ($e->portal_ref ?: $e->canonical_ref) . ' off the property these adverts sit on (not the same property)';
                }
            }
        }

        return null;
    }

    /** why two whole files may not be merged, or null. $refsA / $refsB = the canonical refs of the adverts each holds. */
    public function filesConflict(int $agencyId, int $fileA, int $fileB, array $refsA = [], array $refsB = []): ?string
    {
        $rows = $this->active($agencyId);
        if (! $rows) {
            return null;
        }
        $a = $this->family($agencyId, $fileA);
        $b = $this->family($agencyId, $fileB);
        foreach ($rows as $e) {
            $from = (int) $e->file_id;
            $to = (int) $e->new_file_id;
            if ((in_array($from, $a, true) && in_array($to, $b, true)) || (in_array($from, $b, true) && in_array($to, $a, true))) {
                return 'an agent split one off the other (not the same property)';
            }
            if ($e->canonical_ref !== null && ((in_array($from, $a, true) && in_array($e->canonical_ref, $refsB, true)) || (in_array($from, $b, true) && in_array($e->canonical_ref, $refsA, true)))) {
                return 'an agent marked advert ' . ($e->portal_ref ?: $e->canonical_ref) . ' as not the same property as the other file';
            }
        }

        return null;
    }
}
