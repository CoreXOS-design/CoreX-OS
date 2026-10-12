<?php

namespace App\Services\TitleDeeds;

use App\Models\TitleDeeds\TitleDeedSetting;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/title-deed-register.md section 18 - the unique reference number of a title deed ("TD-00042").
 *
 * Per agency, never reused, safe when two deeds are saved at the same moment: the agency's counter row is locked FOR UPDATE inside the
 * CALLER'S transaction (the same idiom as the proforma numbers) and incremented there, so the number is permanent when the create commits
 * and nobody ever sees it if the create rolls back. The unique indexes on title_deeds are the backstop. If two FIRST allocations for a brand-new
 * agency ever deadlock, MySQL aborts one and the caller's transaction retries (TitleDeedService::create uses 3 attempts).
 */
class TitleDeedReferenceService
{
    /**
     * Allocate the agency's next reference. Must run inside a DB transaction (TitleDeed's `creating` event is always inside the
     * creating transaction or an implicit single-statement one; if there is none, one is opened here so the lock still holds).
     *
     * @return array{0:int,1:string} [sequence number, printed reference]
     */
    public function allocate(int $agencyId): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->allocate($agencyId));
        }

        // Lock the counter row FIRST. (Insert-if-missing on every call takes a shared lock that two processes then both try to upgrade - a deadlock
        // that a real concurrency test caught.) Only when the agency has no counter yet is it created, and then locked.
        $row = DB::table('title_deed_sequences')->where('agency_id', $agencyId)->lockForUpdate()->first();
        if (! $row) {
            DB::table('title_deed_sequences')->insertOrIgnore(['agency_id' => $agencyId, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('title_deed_sequences')->where('agency_id', $agencyId)->lockForUpdate()->first();
        }

        $sequence = (int) $row->next_value;
        DB::table('title_deed_sequences')->where('agency_id', $agencyId)->update(['next_value' => $sequence + 1, 'updated_at' => now()]);

        return [$sequence, $this->format(TitleDeedSetting::referencePrefixFor($agencyId), $sequence)];
    }

    /**
     * Number every deed that has no reference yet, per agency, in the order it was logged (created_at, then id) - archived deeds included, so no number is
     * ever handed out twice - and set each agency's counter to the highest number + 1. Used by the migration; additive: only the reference columns change.
     */
    public function backfillExisting(string $prefix = 'TD'): void
    {
        $now = now();
        foreach (DB::table('title_deeds')->select('agency_id')->distinct()->pluck('agency_id') as $agencyId) {
            $n = (int) (DB::table('title_deeds')->where('agency_id', $agencyId)->max('reference_no') ?? 0);
            // Date logged first, the id as the tiebreak. (Plain ordered ids: a chunked read would re-order by id and break "in the order logged".)
            $ids = DB::table('title_deeds')->where('agency_id', $agencyId)->whereNull('reference_no')->orderBy('created_at')->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $n++;
                DB::table('title_deeds')->where('id', $id)->update(['reference_no' => $n, 'reference' => $this->format($prefix, $n)]);
            }
            DB::table('title_deed_sequences')->updateOrInsert(['agency_id' => $agencyId], ['next_value' => $n + 1, 'updated_at' => $now, 'created_at' => $now]);
        }
    }

    /** "TD" + 42 -> "TD-00042". A sequence over 99999 simply grows longer. */
    public function format(string $prefix, int $sequence): string
    {
        return $prefix . '-' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    /** What a person typed as a prefix, made safe: letters and digits only, upper-case, 1-10 characters ("td-" -> "TD"). Empty = null (the default). */
    public static function cleanPrefix(?string $typed): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $typed));

        return $clean === '' ? null : substr($clean, 0, 10);
    }
}
