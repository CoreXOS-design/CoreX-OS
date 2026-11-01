<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\Prospecting\TrackedProperty;

/**
 * AT-520 4.13d: the size rule changed after the unwind was applied (a stand size is never compared for flats, adverts at the file's own address never split on size).
 * This re-reads every group the unwind moved ONLY because of size and decides again with today's rule: a group that no longer clashes with what stayed on the file
 * (nor with the other groups coming back) is put back through the unwind's own journal, restricted to that group's destination file. Everything else stays moved.
 */
final class UnwindSizeReview
{
    public function __construct(private readonly MergeGuard $guard, private readonly UnwindReverser $reverser)
    {
    }

    /**
     * @param  string      $reportJson  the unwind's apply report (JSON)
     * @param  string      $journal     the unwind's journal (jsonl)
     * @return array{size_moves:int, kept:int, reversed:int, files:int, reversal:array<string,int>, destination_files:list<int>}
     */
    public function run(int $agencyId, string $reportJson, string $journal, bool $apply, ?string $journalOut = null): array
    {
        $report = json_decode((string) file_get_contents($reportJson), true) ?: [];
        $out = ['size_moves' => 0, 'kept' => 0, 'reversed' => 0, 'files' => 0, 'reversal' => [], 'destination_files' => []];
        foreach ($report['rows'] ?? [] as $row) {
            $sizeGroups = array_values(array_filter($row['moved'], fn ($m) => ($m['reason_kind'] ?? '') === 'size'));
            if ($sizeGroups === []) {
                continue;
            }
            $fid = (int) $row['file'];
            $stay = $this->guard->advertsOf($agencyId, [$fid])[$fid];
            $this->guard->markAtFile($agencyId, $fid, $stay);
            $back = [];
            foreach ($sizeGroups as $m) {
                $out['size_moves']++;
                if (! preg_match('/#(\d+)/', (string) $m['to'], $d)) {
                    $out['kept']++;
                    continue;
                }
                $dest = (int) $d[1];
                $adverts = $this->guard->advertsOf($agencyId, [$dest])[$dest];
                $this->guard->markAtFile($agencyId, $fid, $adverts);
                $clash = $this->guard->conflictBetween($agencyId, $adverts, $stay);
                foreach ($back as $other) {
                    $clash ??= $this->guard->conflictBetween($agencyId, $adverts, $other['adverts']);
                }
                if ($clash !== null || $adverts->isEmpty()) {
                    $out['kept']++;
                    continue;
                }
                $back[] = ['dest' => $dest, 'adverts' => $adverts];
            }
            if ($back !== []) {
                $out['files']++;
                $out['reversed'] += count($back);
                array_push($out['destination_files'], ...array_column($back, 'dest'));
            }
        }
        if ($out['destination_files'] !== []) {
            $out['reversal'] = $this->reverser->reverse($journal, $apply, $out['destination_files'], $journalOut);
        }

        return $out;
    }
}
