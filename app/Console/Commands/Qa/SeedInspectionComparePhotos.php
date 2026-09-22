<?php

namespace App\Console\Commands\Qa;

use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * QA1 DEMO/TEST SEEDING ONLY — refuses to run anywhere but APP_ENV=qa
 * (see handle()). Never touches staging or live; there is no code path in
 * this file that can reach either.
 *
 * Johan, 2026-09-22: he cannot finish verifying the property/5792 Compare
 * — In vs Out view because the OUT inspection has zero photos on either
 * the room level or the item level, so "Match photos", unmatch, and
 * match-survives-reload are all untestable. This command gives the OUT
 * inspection real, resolvable photos to work with, without uploading
 * anything.
 *
 * Approach: NEW `rental_inspection_photos` rows on the OUT inspection
 * pointing at the SAME `storage_path` values the IN inspection's photos
 * already use — no file copy. This is safe, not merely convenient:
 * `RentalInspectionPhoto::archive()` (app/Models/RentalInspectionPhoto.php)
 * is `forceFill(['archived_by_user_id' => ...])->save(); $this->delete();`
 * — a SoftDeletes call, and there is no `Storage::delete()` anywhere in
 * that model or in the archive/untag/tag controller actions
 * (RentalInspectionRecordingController). Archiving or soft-deleting either
 * side's photo row can never orphan the other side's reference to the
 * same file, because nothing in this codebase ever deletes the physical
 * file for this model. If that ever changes, this comment is the flag to
 * re-check this command.
 *
 * Idempotent: re-running skips any room/item that already has a photo on
 * the OUT inspection, so this is safe to run again after a fresh seed
 * (e.g. a `migrate:fresh` against corex_qa1 that recreates property 5792,
 * its rooms, and its IN inspection from whatever process seeds that base
 * state) without piling up duplicates.
 */
class SeedInspectionComparePhotos extends Command
{
    protected $signature = 'qa:seed-inspection-compare-photos';

    protected $description = 'QA1 ONLY — seed OUT-side photos on property 5792\'s draft out-inspection so the Compare — In vs Out view has content on both sides to test match/unmatch against.';

    private const PROPERTY_ID = 5792;

    /** Room label => how many of that room's IN-side room-level photos to duplicate onto the OUT side. */
    private const ROOMS = [
        'Kitchen' => 3,
        'Bedroom 1' => 3,
        'Study' => 3,
    ];

    private const ITEM_ROOM_LABEL = 'Bedroom 1';
    private const ITEM_LABEL = 'Ceiling';

    public function handle(): int
    {
        if (! app()->environment('qa')) {
            $this->error('Refusing to run: this seeds fake inspection data and is guarded to APP_ENV=qa only. Current environment: ' . app()->environment());

            return self::FAILURE;
        }

        $inInspection = RentalInspection::where('property_id', self::PROPERTY_ID)
            ->where('type', 'in')
            ->orderByDesc('id')
            ->first();

        if (! $inInspection) {
            $this->error('No "in" inspection found for property ' . self::PROPERTY_ID . '. Nothing to duplicate photos from.');

            return self::FAILURE;
        }

        $outInspection = RentalInspection::where('property_id', self::PROPERTY_ID)
            ->where('type', 'out')
            ->where('status', 'draft')
            ->orderByDesc('id')
            ->first();

        if (! $outInspection) {
            $this->error('No DRAFT "out" inspection found for property ' . self::PROPERTY_ID . '. Refusing to guess which out-inspection to seed, and refusing to touch a non-draft one.');

            return self::FAILURE;
        }

        $seedUser = User::find(22) ?? User::query()->orderBy('id')->first();
        if (! $seedUser) {
            $this->error('No user found to attribute the seeded photos to.');

            return self::FAILURE;
        }

        $this->info("Seeding onto OUT inspection #{$outInspection->id} (property " . self::PROPERTY_ID . '), sourcing from IN inspection #' . $inInspection->id . '.');

        foreach (self::ROOMS as $label => $count) {
            $this->seedRoomLevel($inInspection, $outInspection, $label, $count, $seedUser);
        }

        $this->seedItemLevel($inInspection, $outInspection, $seedUser);

        return self::SUCCESS;
    }

    private function seedRoomLevel(RentalInspection $in, RentalInspection $out, string $roomLabel, int $count, User $seedUser): void
    {
        $room = PropertyRoom::where('property_id', self::PROPERTY_ID)->where('label', $roomLabel)->first();
        if (! $room) {
            $this->warn("Room '{$roomLabel}' not found on property " . self::PROPERTY_ID . ' — skipped.');

            return;
        }

        $alreadyOnOut = RentalInspectionPhoto::where('rental_inspection_id', $out->id)
            ->where('property_room_id', $room->id)
            ->whereNull('rental_inspection_observation_id')
            ->count();

        if ($alreadyOnOut > 0) {
            $this->line("  {$roomLabel}: OUT side already has {$alreadyOnOut} room-level photo(s) — skipped (idempotent).");

            return;
        }

        $sourcePhotos = RentalInspectionPhoto::where('rental_inspection_id', $in->id)
            ->where('property_room_id', $room->id)
            ->whereNull('rental_inspection_observation_id')
            ->orderBy('id')
            ->take($count)
            ->get();

        if ($sourcePhotos->isEmpty()) {
            $this->warn("  {$roomLabel}: no IN-side room-level photos found to duplicate — skipped.");

            return;
        }

        foreach ($sourcePhotos as $source) {
            RentalInspectionPhoto::create([
                'agency_id' => $out->agency_id,
                'rental_inspection_id' => $out->id,
                'rental_inspection_observation_id' => null,
                'property_room_id' => $room->id,
                'storage_path' => $source->storage_path,
                'uploaded_by_user_id' => $seedUser->id,
                'tagged_at' => now(),
                'tagged_by_user_id' => $seedUser->id,
                'client_idempotency_key' => (string) Str::uuid(),
                'file_size_bytes' => $source->file_size_bytes,
            ]);
        }

        $this->info("  {$roomLabel}: seeded {$sourcePhotos->count()} room-level photo(s) on the OUT side.");
    }

    private function seedItemLevel(RentalInspection $in, RentalInspection $out, User $seedUser): void
    {
        $room = PropertyRoom::where('property_id', self::PROPERTY_ID)->where('label', self::ITEM_ROOM_LABEL)->first();
        if (! $room) {
            $this->warn(self::ITEM_ROOM_LABEL . ' not found on property ' . self::PROPERTY_ID . ' — item-level seed skipped.');

            return;
        }

        $item = RentalInspectionItem::where('property_room_id', $room->id)->where('label', self::ITEM_LABEL)->first();
        if (! $item) {
            $this->warn(self::ITEM_ROOM_LABEL . ' ' . self::ITEM_LABEL . ' item not found — item-level seed skipped.');

            return;
        }

        $alreadyHasObservation = RentalInspectionObservation::where('rental_inspection_id', $out->id)
            ->where('rental_inspection_item_id', $item->id)
            ->exists();

        if ($alreadyHasObservation) {
            $this->line('  ' . self::ITEM_ROOM_LABEL . ' ' . self::ITEM_LABEL . ': OUT side already has an observation for this item — skipped (idempotent).');

            return;
        }

        $sourcePhoto = RentalInspectionPhoto::where('rental_inspection_id', $in->id)
            ->whereNotNull('rental_inspection_observation_id')
            ->whereHas('observation', fn ($q) => $q->where('rental_inspection_item_id', $item->id))
            ->orderBy('id')
            ->first();

        if (! $sourcePhoto) {
            $this->warn('  No IN-side photo found tagged to the ' . self::ITEM_ROOM_LABEL . ' ' . self::ITEM_LABEL . ' observation — item-level seed skipped.');

            return;
        }

        $observation = RentalInspectionObservation::create([
            'agency_id' => $out->agency_id,
            'rental_inspection_id' => $out->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $seedUser->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'notes' => 'QA seed data (qa:seed-inspection-compare-photos) — not a real inspection finding.',
            'source' => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            'client_idempotency_key' => (string) Str::uuid(),
        ]);

        RentalInspectionPhoto::create([
            'agency_id' => $out->agency_id,
            'rental_inspection_id' => $out->id,
            'rental_inspection_observation_id' => $observation->id,
            'property_room_id' => $room->id,
            'storage_path' => $sourcePhoto->storage_path,
            'uploaded_by_user_id' => $seedUser->id,
            'tagged_at' => now(),
            'tagged_by_user_id' => $seedUser->id,
            'client_idempotency_key' => (string) Str::uuid(),
            'file_size_bytes' => $sourcePhoto->file_size_bytes,
        ]);

        $this->info('  ' . self::ITEM_ROOM_LABEL . ' ' . self::ITEM_LABEL . ': seeded 1 item-level observation + photo on the OUT side.');
    }
}
