<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Support\Facades\DB;

/**
 * AT-520 4.13: the way back for `property-identity:unwind --apply`. Reads that run's journal (one JSON line per change) from the last line to the first and
 * puts every row back: adverts return to the file they left, restored files go back to "duplicate of" the file they were restored from, and a file the unwind
 * created (now empty) is flagged duplicate of the file it was made from - never deleted. A row that has changed since (another rule moved it again) is left alone and counted.
 */
final class UnwindReverser
{
    public function __construct(private readonly TrackedPropertyMerger $merger)
    {
    }

    /** @return array<string,int> */
    public function reverse(string $journalPath, bool $apply): array
    {
        $out = ['lines' => 0, 'adverts_returned' => 0, 'files_back_to_duplicate' => 0, 'created_files_closed' => 0, 'left_alone' => 0];
        $lines = array_values(array_filter(array_map('trim', file($journalPath) ?: [])));
        foreach (array_reverse($lines) as $line) {
            $e = json_decode($line, true);
            if (! is_array($e) || ($e['step'] ?? null) !== 'unwind' && ($e['step'] ?? null) !== 'split-over-merged') {
                continue;
            }
            $out['lines']++;
            $table = (string) $e['table'];
            $id = (int) $e['id'];
            $col = (string) $e['column'];
            if ($col === 'tracked_property_id') {
                $n = $apply
                    ? DB::table($table)->where('id', $id)->where('tracked_property_id', $e['new'])->update(['tracked_property_id' => $e['old']])
                    : (int) DB::table($table)->where('id', $id)->where('tracked_property_id', $e['new'])->exists();
                $n > 0 ? $out['adverts_returned']++ : $out['left_alone']++;
            } elseif ($table === 'tracked_properties' && $col === 'duplicate_of_tracked_property_id') {
                $apply && DB::table('tracked_properties')->where('id', $id)->whereNull('duplicate_of_tracked_property_id')->update(['duplicate_of_tracked_property_id' => $e['old']]);
            } elseif ($table === 'tracked_properties' && $col === 'status') {
                $n = $apply ? DB::table('tracked_properties')->where('id', $id)->where('status', $e['new'])->update(['status' => $e['old'], 'updated_at' => now()]) : 1;
                $n > 0 ? $out['files_back_to_duplicate']++ : $out['left_alone']++;
            } elseif ($table === 'tracked_properties' && $col === 'created_from') {
                if (! $this->merger->isEmptyShell($id)) {
                    $out['left_alone']++;
                    continue;
                }
                if ($apply) {
                    $this->merger->flagDuplicate($id, (int) $e['new'], 'unwind-reverse');
                }
                $out['created_files_closed']++;
            }
        }

        return $out;
    }
}
