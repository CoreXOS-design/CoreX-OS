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

    /**
     * @param  list<int>|null $onlyFiles  put back only what went to (or was created as) these destination files; null = everything in the journal
     * @param  string|null    $journalOut when applying: every change made here is journalled to this file (step "unwind-reverse", old and new swapped)
     * @return array<string,int>
     */
    public function reverse(string $journalPath, bool $apply, ?array $onlyFiles = null, ?string $journalOut = null): array
    {
        $only = $onlyFiles === null ? null : array_map('intval', $onlyFiles);
        if ($apply && $journalOut) {
            $this->merger->journalTo($journalOut);
        }
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
            if ($only !== null && ! in_array($col === 'tracked_property_id' ? (int) $e['new'] : $id, $only, true)) {
                continue;
            }
            if ($col === 'tracked_property_id') {
                $n = $apply
                    ? DB::table($table)->where('id', $id)->where('tracked_property_id', $e['new'])->update(['tracked_property_id' => $e['old']])
                    : (int) DB::table($table)->where('id', $id)->where('tracked_property_id', $e['new'])->exists();
                $n > 0 ? $out['adverts_returned']++ : $out['left_alone']++;
                $apply && $n > 0 && $this->merger->log('unwind-reverse', $table, $id, 'tracked_property_id', $e['new'], $e['old']);
            } elseif ($table === 'tracked_properties' && $col === 'duplicate_of_tracked_property_id') {
                if ($apply && DB::table('tracked_properties')->where('id', $id)->whereNull('duplicate_of_tracked_property_id')->update(['duplicate_of_tracked_property_id' => $e['old']])) {
                    $this->merger->log('unwind-reverse', 'tracked_properties', $id, 'duplicate_of_tracked_property_id', null, $e['old']);
                }
            } elseif ($table === 'tracked_properties' && $col === 'status') {
                $n = $apply ? DB::table('tracked_properties')->where('id', $id)->where('status', $e['new'])->update(['status' => $e['old'], 'updated_at' => now()]) : 1;
                $n > 0 ? $out['files_back_to_duplicate']++ : $out['left_alone']++;
                $apply && $n > 0 && $this->merger->log('unwind-reverse', 'tracked_properties', $id, 'status', $e['new'], $e['old']);
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
        if ($apply && $journalOut) {
            $this->merger->journalTo(null);
        }

        return $out;
    }
}
