<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\PropertyIdentityApplier;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Support\Facades\DB;

/**
 * AT-520 step 4.8 (conductor walk 31 Oct, 13 Newport Road): an advert whose OWN address, suburb or region contradicts the property file it sits on is
 * on the wrong file - e.g. a Pam Golding North Durban advert for "46 Heleza Boulevard, Sibaya" on a Port Edward house. This finds every such advert
 * and, on apply, moves it back to its own file: the existing file at its address when the matcher is confident, otherwise a new file made from the
 * advert's own public facts. A file left with nothing is flagged duplicate, never deleted. Every move is journalled (the merger's journal).
 */
final class AdvertLinkAudit
{
    public function __construct(
        private readonly AdvertEvidence $evidence,
        private readonly TrackedPropertyMerger $merger,
        private readonly PropertyIdentityApplier $applier,
        private readonly PropertyIdentityResolver $door,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(int $agencyId, bool $apply): array
    {
        $out = ['audited' => 0, 'contradictions' => 0, 'by_kind' => ['number' => 0, 'street' => 0, 'region' => 0], 'moved_to_existing' => 0, 'moved_to_new' => 0, 'files_emptied' => 0, 'samples' => []];

        DB::table('prospecting_listings as l')
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted']);
            })
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->whereNotNull('l.canonical_ref')
            ->whereRaw("(TRIM(COALESCE(t.street_name,'')) <> '' OR TRIM(COALESCE(t.suburb,'')) <> '')")
            ->orderBy('l.id')->select('l.id', 'l.canonical_ref', 'l.address', 'l.suburb', 'l.tracked_property_id', 'l.portal_source', 'l.agency_name')
            ->chunk(1000, function ($rows) use ($agencyId, $apply, &$out) {
                $files = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->whereIn('id', $rows->pluck('tracked_property_id')->unique()->all())->get()->keyBy('id');
                foreach ($rows as $l) {
                    $out['audited']++;
                    $file = $files[$l->tracked_property_id] ?? null;
                    if ($file === null) {
                        continue;
                    }
                    $bad = $this->evidence->advertVsFile($agencyId, ['address' => $l->address, 'suburb' => $l->suburb], $file);
                    if ($bad === null) {
                        continue;
                    }
                    $out['contradictions']++;
                    $out['by_kind'][$bad['kind']]++;
                    if (count($out['samples']) < 400) {
                        $out['samples'][] = ['kind' => $bad['kind'], 'advert' => $l->canonical_ref, 'agency' => $l->agency_name, 'file' => (int) $l->tracked_property_id, 'why' => $bad['reason']];
                    }
                    if ($apply) {
                        $this->moveOut($agencyId, $l, $file, $out);
                    }
                }
            });

        return $out;
    }

    /** @param array<string,mixed> $out */
    private function moveOut(int $agencyId, object $l, TrackedProperty $file, array &$out): void
    {
        $target = null;
        if (trim((string) $l->address) !== '') {
            $found = $this->door->find($agencyId, ['address' => $l->address, 'suburb' => $l->suburb]);
            if ($found->trackedProperty !== null && in_array($found->confidence, ['exact', 'high'], true) && (int) $found->trackedProperty->id !== (int) $file->id) {
                $target = (int) $found->trackedProperty->id;
            }
        }
        $toExisting = $target !== null;
        $target ??= $this->applier->createSplitFile($file, [(object) ['id' => $l->id]], true);
        if ($target === null) {
            return;
        }
        $this->merger->moveAdvert($agencyId, (string) $l->canonical_ref, (int) $file->id, (int) $target, true, 'link-audit');
        $out[$toExisting ? 'moved_to_existing' : 'moved_to_new']++;
        if ($this->merger->isEmptyShell((int) $file->id)) {
            $this->merger->flagDuplicate((int) $file->id, (int) $target, 'link-audit');
            $out['files_emptied']++;
        }
    }
}
