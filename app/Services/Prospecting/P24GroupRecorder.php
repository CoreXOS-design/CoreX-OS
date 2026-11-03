<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Facades\DB;

/**
 * Records P24's own statement that several listing numbers are ONE property ("Listed by N Estate Agencies",
 * .ai/specs/mic-work-tab.md §4 R3). Global public fact; the matcher (cc4) reads p24_group_members.
 */
final class P24GroupRecorder
{
    /**
     * @param  list<string> $listingNumbers digits only
     * @return int rows written (new + refreshed)
     */
    public function record(string $groupId, array $listingNumbers, ?int $groupSize = null): int
    {
        $numbers = array_values(array_unique($listingNumbers));
        if ($groupId === '' || $numbers === []) {
            return 0;
        }
        $now = now();
        $size = $groupSize ?? count($numbers);
        $rows = array_map(fn (string $n) => [
            'p24_group_id' => $groupId, 'listing_number' => $n, 'group_size' => $size,
            'first_seen_at' => $now, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ], $numbers);

        // first_seen_at / created_at are only written on insert
        DB::table('p24_group_members')->upsert($rows, ['p24_group_id', 'listing_number'], ['group_size', 'seen_at', 'updated_at']);

        return count($rows);
    }
}
