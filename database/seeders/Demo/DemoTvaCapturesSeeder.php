<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Prospecting\TvaContactCapture;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo TVA (deeds-office contact lookup) capture data — the IMPORT side of
 * the Deeds Capture screen. DemoDeedsSeeder stamps 20 tracked_properties
 * with deeds fields and a single owner_contact_id each, but never populates
 * tracked_property_owners / tva_contact_captures / tva_contact_capture_items
 * — the tables the screen's TVA-matching workflow actually reads. Without
 * them the import panel is permanently empty, even though 20 deeds captures
 * exist.
 *
 * Builds a small, realistic story across a handful of the existing 20 deeds
 * rows (never touches the other 480+ tracked_properties):
 *   - 2 MATCHED captures — a TVA person lookup whose id_number matches a
 *     tracked_property_owners row, so the screen nests it under that
 *     property (DeedsCaptureController::index() re-derives this match live
 *     by id_number — see that method's docblock). One capture is left with
 *     an item already ingested + one still pending, to show a workflow in
 *     progress rather than a frozen snapshot.
 *   - 1 OPEN CONFLICT — a second tracked_property_owners row on a third
 *     property whose details disagree with the existing owner and is
 *     flagged, unresolved.
 *   - 2 STANDALONE/unmatched captures — id_numbers matching no owner on
 *     file, exactly the "needs review" case the matching workflow exists for.
 *
 * IDEMPOTENT BY CONSTRUCTION:
 *   - tracked_property_owners rows are guarded by a direct existence check
 *     on (tracked_property_id, id_number) before insert — never duplicated.
 *   - tva_contact_captures rows use firstOrCreate keyed on
 *     (agency_id, id_number) — one capture per distinct person, matching
 *     how a real TVA lookup works (you look a person up once).
 *   - tva_contact_capture_items are only ever inserted the run a capture is
 *     first created (guarded on wasRecentlyCreated) — never duplicated on
 *     a re-run that finds the capture already present.
 */
class DemoTvaCapturesSeeder
{
    /** @return array{owners:int, captures:int, items:int, note?:string} */
    public function run(int $agencyId = 1): array
    {
        $candidates = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->where('capture_kind', 'deeds_capture')
            ->whereNull('promoted_to_property_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'owner_contact_id']);

        if ($candidates->count() < 3) {
            return ['owners' => 0, 'captures' => 0, 'items' => 0, 'note' => 'Skipped — fewer than 3 deeds-captured tracked_properties present (run DemoDeedsSeeder first).'];
        }

        $userIds = DB::table('users')->where('agency_id', $agencyId)
            ->whereIn('role', ['admin', 'agent', 'branch_manager'])
            ->orderBy('id')->pluck('id')->all();
        if (empty($userIds)) {
            return ['owners' => 0, 'captures' => 0, 'items' => 0, 'note' => 'Skipped — agency has no users.'];
        }

        [$tpMatchedLinked, $tpMatchedFresh, $tpConflict] = [$candidates[0], $candidates[1], $candidates[2]];

        $ownersCreated = 0;
        $capturesCreated = 0;
        $itemsCreated = 0;

        // ── Scenario 1: matched capture, person already linked to an existing Contact ──
        $existingOwnerContact = DB::table('contacts')->where('id', $tpMatchedLinked->owner_contact_id)->first();
        if ($existingOwnerContact && $existingOwnerContact->id_number) {
            $ownersCreated += $this->ensureOwnerRow($tpMatchedLinked->id, [
                'contact_id'           => $existingOwnerContact->id,
                'matched_contact_at'   => now(),
                'name'                 => trim($existingOwnerContact->first_name . ' ' . $existingOwnerContact->last_name),
                'id_number'            => $existingOwnerContact->id_number,
                'id_type'              => 'sa_id',
                'is_primary'           => true,
                'role'                 => 'owner',
                'ownership_status'     => 'current',
            ]);

            [$capturesCreated1, $itemsCreated1] = $this->ensureCapture(
                $agencyId,
                $userIds[0],
                $tpMatchedLinked->id,
                $existingOwnerContact->id_number,
                explode(' ', $existingOwnerContact->first_name, 2)[0],
                $existingOwnerContact->last_name,
                matchedContactId: $existingOwnerContact->id,
                itemStates: ['ingested', 'pending'], // one already processed, one still needs review
            );
            $capturesCreated += $capturesCreated1;
            $itemsCreated += $itemsCreated1;
        }

        // ── Scenario 2: matched capture, fresh scrape — owner row exists but is NOT yet linked to a Contact ──
        $freshName = \Database\Seeders\Demo\DemoNames::name('tva-fresh-owner-' . $tpMatchedFresh->id);
        [$freshFirst, $freshLast] = $this->splitName($freshName);
        $freshIdNumber = $this->fakeSaId($tpMatchedFresh->id + 500);

        $ownersCreated += $this->ensureOwnerRow($tpMatchedFresh->id, [
            'contact_id'         => null,
            'matched_contact_at' => null,
            'name'               => $freshName,
            'id_number'          => $freshIdNumber,
            'id_type'            => 'sa_id',
            'is_primary'         => false,
            'role'               => 'owner',
            'ownership_status'   => 'current',
        ]);

        [$capturesCreated2, $itemsCreated2] = $this->ensureCapture(
            $agencyId,
            $userIds[1 % count($userIds)],
            $tpMatchedFresh->id,
            $freshIdNumber,
            $freshFirst,
            $freshLast,
            matchedContactId: null,
            itemStates: ['pending', 'pending'],
        );
        $capturesCreated += $capturesCreated2;
        $itemsCreated += $itemsCreated2;

        // ── Scenario 3: open conflict — a second owner row disagreeing with what's on file ──
        $conflictName = \Database\Seeders\Demo\DemoNames::name('tva-conflict-owner-' . $tpConflict->id);
        $ownersCreated += $this->ensureOwnerRow($tpConflict->id, [
            'contact_id'           => null,
            'matched_contact_at'   => null,
            'name'                 => $conflictName,
            'id_number'            => $this->fakeSaId($tpConflict->id + 900),
            'id_type'              => 'sa_id',
            'is_primary'           => false,
            'role'                 => 'owner',
            'ownership_status'     => 'current',
            'conflict_flagged_at'  => now()->subDays(3),
            'conflict_resolved_at' => null,
        ]);

        // ── Scenario 4 + 5: standalone/unmatched captures — id_number matches no owner on file ──
        foreach ([1, 2] as $n) {
            $standaloneName = \Database\Seeders\Demo\DemoNames::name('tva-standalone-' . $agencyId . '-' . $n);
            [$sFirst, $sLast] = $this->splitName($standaloneName);
            $standaloneId = $this->fakeSaId(700000 + $n * 37);

            [$c, $it] = $this->ensureCapture(
                $agencyId,
                $userIds[($n + 1) % count($userIds)],
                null,
                $standaloneId,
                $sFirst,
                $sLast,
                matchedContactId: null,
                itemStates: $n === 1 ? ['pending', 'pending'] : ['pending'],
            );
            $capturesCreated += $c;
            $itemsCreated += $it;
        }

        $note = "TVA: +{$ownersCreated} owner rows, +{$capturesCreated} captures, +{$itemsCreated} items.";

        return ['owners' => $ownersCreated, 'captures' => $capturesCreated, 'items' => $itemsCreated, 'note' => $note];
    }

    private function ensureOwnerRow(int $trackedPropertyId, array $attrs): int
    {
        $exists = DB::table('tracked_property_owners')
            ->where('tracked_property_id', $trackedPropertyId)
            ->where('id_number', $attrs['id_number'])
            ->exists();
        if ($exists) {
            return 0;
        }

        DB::table('tracked_property_owners')->insert(array_merge([
            'tracked_property_id' => $trackedPropertyId,
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $attrs));

        return 1;
    }

    /** @return array{0:int,1:int} [capturesCreated, itemsCreated] */
    private function ensureCapture(
        int $agencyId,
        int $capturedByUserId,
        ?int $trackedPropertyId,
        string $idNumber,
        string $firstName,
        string $lastName,
        ?int $matchedContactId,
        array $itemStates,
    ): array {
        $capture = TvaContactCapture::firstOrCreate(
            ['agency_id' => $agencyId, 'id_number' => $idNumber],
            [
                'captured_by_user_id' => $capturedByUserId,
                'tracked_property_id' => $trackedPropertyId,
                'matched_contact_id'  => $matchedContactId,
                'first_name'          => $firstName,
                'surname'             => $lastName,
                'source'              => 'tva',
                'consent_status'      => 'granted',
            ]
        );

        if (!$capture->wasRecentlyCreated) {
            return [0, 0];
        }

        $itemsCreated = 0;
        foreach ($itemStates as $idx => $state) {
            $isCell = $idx % 2 === 0;
            DB::table('tva_contact_capture_items')->insert([
                'tva_contact_capture_id' => $capture->id,
                'type'                   => $isCell ? 'cell' : 'email',
                'value'                  => $isCell
                    ? $this->fakePhone($capture->id * 13 + $idx)
                    : strtolower($firstName . '.' . $lastName . $idx . '@example.com'),
                'date'                   => now()->subDays(5 + $idx)->toDateString(),
                'link_date'              => now()->subDays(5 + $idx)->toDateString(),
                'opted_out'              => false,
                'ingested_at'            => $state === 'ingested' ? now()->subDays(1) : null,
                'ingested_contact_id'    => $state === 'ingested' ? $matchedContactId : null,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
            $itemsCreated++;
        }

        return [1, $itemsCreated];
    }

    private function splitName(string $full): array
    {
        $parts = explode(' ', trim($full), 2);
        return [$parts[0], $parts[1] ?? 'Demo'];
    }

    private function fakeSaId(int $seed): string
    {
        $year = str_pad((string) ($seed % 100), 2, '0', STR_PAD_LEFT);
        $month = str_pad((string) (1 + ($seed % 12)), 2, '0', STR_PAD_LEFT);
        $day = str_pad((string) (1 + ($seed % 28)), 2, '0', STR_PAD_LEFT);
        return $year . $month . $day . '0000080';
    }

    private function fakePhone(int $seed): string
    {
        return '083' . str_pad((string) (1000000 + ($seed * 41) % 8999999), 7, '0', STR_PAD_LEFT);
    }
}
