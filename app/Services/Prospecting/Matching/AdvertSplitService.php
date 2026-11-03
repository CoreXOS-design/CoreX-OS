<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAgencyWorkEvent;
use App\Services\Prospecting\PropertyIdentityApplier;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Support\Facades\DB;

/**
 * mic-work-tab.md 13.12 - "Not the same property". An agent says one advert in the "Listed by N agencies" popover is not the same property as the rest of its
 * file. The advert (and everything that is only its own - its alert rows, portal references, and, because they hang on the advert, its sightings, price history,
 * photos and sole-mandate wording) moves onto a NEW file built from its own captured address; the claim, comments and file history stay with the original. The
 * split is remembered (`mic_match_exclusions`, see MatchExclusions) so no rule puts it back, written to a journal, and can be undone from either.
 */
final class AdvertSplitService
{
    private const MOVE_TABLES = ['tracked_property_external_refs', 'prospecting_listings', 'portal_listings', 'p24_listings', 'pp_alert_listings'];

    public function __construct(private readonly TrackedPropertyMerger $merger, private readonly PropertyIdentityApplier $applier)
    {
    }

    /** where the journal files go (config, falling back to storage when the volume is not writable) */
    public function journalPath(int $agencyId): string
    {
        $dir = (string) config('prospecting.split_journal_dir');
        if ($dir === '' || (! is_dir($dir) && ! @mkdir($dir, 0775, true)) || ! is_writable($dir)) {
            $dir = storage_path('app/mic-unwind');
            @mkdir($dir, 0775, true);
        }

        return $dir . "/advert-split-agency{$agencyId}-journal-" . now()->format('Y-m-d') . '.jsonl';
    }

    /**
     * @return array{split_id:int, from_file_id:int, new_file_id:int, new_file_address:string, adverts_left_on_file:int, agencies_left_on_file:int}
     *
     * @throws AdvertSplitException 404 / 409 with a plain-words message
     */
    public function split(object $listing, object $user): array
    {
        $agencyId = (int) $listing->agency_id;
        $from = (int) $listing->tracked_property_id;
        $orig = $from > 0 ? TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->find($from) : null;
        if (! $orig) {
            throw new AdvertSplitException(409, 'This advert is not on a property file, so there is nothing to split it from.');
        }
        $canonical = $listing->canonical_ref ?: null;
        $here = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $from)->whereNull('deleted_at')->get(['id', 'canonical_ref']);
        $others = $here->filter(fn ($r) => $canonical === null ? (int) $r->id !== (int) $listing->id : $r->canonical_ref !== $canonical);
        if ($others->isEmpty()) {
            throw new AdvertSplitException(409, 'This advert is already the only one on its property.');
        }

        $rows = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $from)->whereNull('deleted_at')
            ->when($canonical !== null, fn ($q) => $q->where('canonical_ref', $canonical), fn ($q) => $q->where('id', $listing->id))->orderBy('id')->get();

        return DB::transaction(function () use ($agencyId, $from, $orig, $canonical, $rows, $listing, $user, $others) {
            $newId = $this->applier->createSplitFile($orig, $rows->all(), true);
            if (! $newId) {
                throw new AdvertSplitException(409, 'The new property could not be made.');
            }
            $moves = [];
            if ($canonical !== null) {
                foreach (self::MOVE_TABLES as $table) {
                    $ids = $this->idsOn($table, $agencyId, $from, $canonical);
                    if ($ids) {
                        DB::table($table)->whereIn('id', $ids)->update(['tracked_property_id' => $newId]);
                        foreach ($ids as $id) {
                            $moves[] = ['table' => $table, 'id' => (int) $id, 'old' => $from, 'new' => $newId];
                        }
                    }
                }
            } else {
                DB::table('prospecting_listings')->where('id', $listing->id)->update(['tracked_property_id' => $newId]);
                $moves[] = ['table' => 'prospecting_listings', 'id' => (int) $listing->id, 'old' => $from, 'new' => $newId];
            }

            $newFile = TrackedProperty::queryWithoutAgencyScope()->find($newId);
            $newAddress = $this->addressOf($newFile, $rows->first());
            $oldAddress = $this->addressOf($orig, null);

            $id = DB::table('mic_match_exclusions')->insertGetId([
                'agency_id' => $agencyId, 'file_id' => $from, 'new_file_id' => $newId, 'listing_id' => (int) $listing->id,
                'portal_ref' => $listing->portal_ref, 'canonical_ref' => $canonical, 'reason' => 'not_same_property',
                'created_by' => (int) $user->id, 'created_at' => now(), 'moves' => json_encode($moves), 'journal' => null,
            ]);
            MatchExclusions::flush();

            $this->audit($agencyId, $from, 'split_off', $newAddress, $oldAddress, "Advert split off to {$newAddress} by {$user->name} on " . now()->format('j M Y'), (int) $user->id);
            $this->audit($agencyId, $newId, 'split_from', $oldAddress, $newAddress, "Split from {$oldAddress} by {$user->name} on " . now()->format('j M Y'), (int) $user->id);

            $journal = $this->journalPath($agencyId);
            @file_put_contents($journal, json_encode([
                'event' => 'split', 'split_id' => $id, 'agency_id' => $agencyId, 'user_id' => (int) $user->id, 'user' => $user->name, 'at' => now()->toIso8601String(),
                'listing_id' => (int) $listing->id, 'portal_ref' => $listing->portal_ref, 'canonical_ref' => $canonical, 'from_file_id' => $from, 'new_file_id' => $newId, 'moves' => $moves,
            ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
            DB::table('mic_match_exclusions')->where('id', $id)->update(['journal' => $journal]);

            $this->merger->refreshStats($agencyId, [$from, $newId]);

            return ['split_id' => (int) $id, 'from_file_id' => $from, 'new_file_id' => (int) $newId, 'new_file_address' => $newAddress, 'adverts_left_on_file' => $others->unique('canonical_ref')->count(),
                'agencies_left_on_file' => $this->agenciesOn($agencyId, $from)];
        });
    }

    /**
     * Put a split back (from the database record, or from a journal line with the same shape). Rows that have moved on since are left alone and counted.
     *
     * @param  array<string,mixed>|null $record  a journal line; null = read the exclusion row
     * @return array{file_id:int, returned:int, left_alone:int, closed_file:bool}
     *
     * @throws AdvertSplitException 404 / 409
     */
    public function undo(int $agencyId, int $splitId, ?object $user = null, ?array $record = null): array
    {
        $row = DB::table('mic_match_exclusions')->where('agency_id', $agencyId)->where('id', $splitId)->first();
        if (! $row) {
            throw new AdvertSplitException(404, 'That split was not found.');
        }
        if ($row->undone_at !== null) {
            throw new AdvertSplitException(409, 'That split has already been undone.');
        }
        $moves = $record['moves'] ?? json_decode((string) $row->moves, true) ?: [];

        return DB::transaction(function () use ($agencyId, $row, $moves, $user) {
            $returned = 0;
            $left = 0;
            foreach (array_reverse($moves) as $m) {
                $n = DB::table($m['table'])->where('id', $m['id'])->where('tracked_property_id', $m['new'])->update(['tracked_property_id' => $m['old']]);
                $n ? $returned++ : $left++;
            }
            DB::table('mic_match_exclusions')->where('id', $row->id)->update(['undone_at' => now(), 'undone_by' => $user?->id]);
            MatchExclusions::flush();
            // the split-off file is empty now: it is soft-deleted (never hard-deleted), so no empty property is left behind
            $closed = $this->closeIfEmpty($agencyId, (int) $row->new_file_id);
            $this->audit($agencyId, (int) $row->file_id, 'split_undone', null, null, 'Advert split undone' . ($user ? " by {$user->name}" : '') . ' on ' . now()->format('j M Y'), $user?->id);
            $this->merger->refreshStats($agencyId, [(int) $row->file_id, (int) $row->new_file_id]);
            if ($row->journal) {
                @file_put_contents((string) $row->journal, json_encode(['event' => 'undo', 'split_id' => (int) $row->id, 'at' => now()->toIso8601String(), 'user_id' => $user?->id, 'returned' => $returned, 'left_alone' => $left, 'closed_file' => $closed ? (int) $row->new_file_id : null], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
            }

            return ['file_id' => (int) $row->file_id, 'returned' => $returned, 'left_alone' => $left, 'closed_file' => $closed];
        });
    }

    /** Soft-delete a split-off file that holds nothing but its own history lines. True when it was closed. */
    public function closeIfEmpty(int $agencyId, int $fileId): bool
    {
        $file = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->find($fileId);
        if (! $file || ! $this->merger->isEmptyShell($fileId, ['tracked_property_agency_work_events'], true)) {
            return false;
        }
        $file->delete();

        return true;
    }

    /** the number of different agencies on the adverts still on a file (same count the card, pill and badge show) */
    private function agenciesOn(int $agencyId, int $fileId): int
    {
        return \App\Services\Prospecting\MicWork\MicWorkList::agenciesIn(
            DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $fileId)->whereNull('deleted_at')->get(['agency_name', 'advertiser_type'])
        );
    }

    /** @return list<int> */
    private function idsOn(string $table, int $agencyId, int $file, string $canonical): array
    {
        $q = DB::table($table)->where('tracked_property_id', $file)->where('canonical_ref', $canonical);
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'agency_id')) {
            $q->where('agency_id', $agencyId);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return $q->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function addressOf(?TrackedProperty $file, ?object $advert): string
    {
        $a = $file ? trim($file->displayAddress()) : '';
        if ($a === '' && $advert) {
            $a = trim((string) $advert->address . ($advert->suburb ? ', ' . $advert->suburb : ''), ' ,');
        }

        return $a !== '' ? mb_substr($a, 0, 90) : 'an unknown address';
    }

    private function audit(int $agencyId, int $fileId, string $kind, ?string $from, ?string $to, string $text, ?int $userId): void
    {
        TrackedPropertyAgencyWorkEvent::queryWithoutAgencyScope()->insert([
            'agency_id' => $agencyId, 'tracked_property_id' => $fileId, 'kind' => $kind,
            'from_value' => $from !== null ? mb_substr($from, 0, 100) : null, 'to_value' => $to !== null ? mb_substr($to, 0, 100) : null,
            'reason' => mb_substr($text, 0, 500), 'user_id' => $userId, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
