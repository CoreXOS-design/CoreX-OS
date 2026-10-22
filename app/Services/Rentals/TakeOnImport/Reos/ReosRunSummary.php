<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;

/**
 * AT-481 / rental-takeon-import.md "reOS review screen rebuild" — the numbers in the review screen's sticky header:
 * "46 leases · 18 ready · 28 need you · 0 imported". Always worked out on the server from the saved rows (ReosQuestions::state), so
 * the header cannot drift from what Import will do.
 */
final class ReosRunSummary
{
    /**
     * @return array{total:int, ready:int, needs:int, failed:int, imported:int, to_update:int, can_import:bool, import_label:string, line:string}
     */
    public static function for(RentalTakeOnImportRun $run): array
    {
        $rows = RentalTakeOnImportRow::query()
            ->where('run_id', $run->id)
            ->get(['id', 'status', 'import_error', 'existing_lease_id', 'decisions_json']);

        $s = ['total' => $rows->count(), 'ready' => 0, 'needs' => 0, 'failed' => 0, 'imported' => 0, 'to_update' => 0];
        foreach ($rows as $row) {
            $state = ReosQuestions::state($row);
            $s[match ($state) {
                ReosQuestions::READY => 'ready',
                ReosQuestions::NEEDS => 'needs',
                ReosQuestions::FAILED => 'failed',
                default => 'imported',
            }]++;
            if ($state === ReosQuestions::READY && $row->existing_lease_id) {
                $s['to_update']++;
            }
        }

        return $s + [
            'can_import' => $s['ready'] > 0,
            'import_label' => $s['ready'] === 1 ? 'Import the 1 ready lease' : "Import the {$s['ready']} ready leases",
            'line' => self::line($s),
        ];
    }

    /** @param array<string,int> $s */
    private static function line(array $s): string
    {
        $parts = [$s['total'] . ($s['total'] === 1 ? ' lease' : ' leases'), $s['ready'] . ' ready', $s['needs'] . ' need you'];
        if ($s['failed'] > 0) {
            $parts[] = $s['failed'] . ' failed';
        }
        $parts[] = $s['imported'] . ' imported';

        return implode(' · ', $parts);
    }
}
