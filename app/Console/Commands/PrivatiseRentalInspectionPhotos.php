<?php

namespace App\Console\Commands;

use App\Models\RentalInspectionPhoto;
use App\Services\Images\PrivateImageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Audit 2026-10-19 B21 - moves the inspection photos recorded BEFORE the private store existed off the public disk.
 *
 * For every photo row that still holds a public /storage URL: the file is re-encoded into the private disk (upright, metadata and GPS
 * stripped), the row is pointed at the private reference, and - only with --remove-public, and only once the private copy is verified and
 * no other record points at the same public file (a fault report or work order raised from the photo shares the address) - the public
 * original is removed. It is a MOVE of evidence, not a delete: the row (archived or not) and the picture both survive.
 *
 * Idempotent and resumable. A legacy row keeps working until it is moved, so the order against a deploy does not matter.
 * Run --dry-run first.
 */
class PrivatiseRentalInspectionPhotos extends Command
{
    protected $signature = 'rental-inspections:privatise-photos {--agency=} {--limit=} {--dry-run} {--remove-public}';

    protected $description = 'Move inspection photos from the public disk to private storage (idempotent; --remove-public also removes the public original once nothing else uses it).';

    public function handle(PrivateImageStore $store): int
    {
        $query = RentalInspectionPhoto::withoutGlobalScopes()->withTrashed()
            ->where('storage_path', 'not like', PrivateImageStore::PREFIX . '%')
            ->orderBy('id');
        if ($agencyId = $this->option('agency')) {
            $query->where('agency_id', $agencyId);
        }
        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $dry = (bool) $this->option('dry-run');
        $counts = ['moved' => 0, 'public_removed' => 0, 'kept_public_shared' => 0, 'missing_file' => 0, 'not_a_storage_url' => 0];

        foreach ($query->get() as $photo) {
            $raw = (string) $photo->getRawOriginal('storage_path');
            $relative = $this->publicRelativePath($raw);
            if ($relative === null) {
                $counts['not_a_storage_url']++;
                continue;
            }
            if (! Storage::disk('public')->exists($relative)) {
                $counts['missing_file']++;
                $this->warn("Photo {$photo->id}: {$relative} is not on the public disk - left as it is.");
                continue;
            }
            if ($dry) {
                $counts['moved']++;
                continue;
            }

            $reference = $store->importFromPublic($relative, "rental-inspections/{$photo->agency_id}/{$photo->rental_inspection_id}");
            if (! $reference || ! $store->exists($reference)) {
                $this->error("Photo {$photo->id}: the private copy could not be made - left as it is.");
                continue;
            }
            DB::table('rental_inspection_photos')->where('id', $photo->id)->update(['storage_path' => $reference]);
            $counts['moved']++;

            if ($this->option('remove-public')) {
                if ($this->publicFileStillUsed($raw, $photo->id)) {
                    $counts['kept_public_shared']++;
                } else {
                    Storage::disk('public')->delete($relative);
                    $thumb = dirname($relative) . '/thumbs/' . basename($relative);
                    if (Storage::disk('public')->exists($thumb)) {
                        Storage::disk('public')->delete($thumb);
                    }
                    $counts['public_removed']++;
                }
            }
        }

        $this->table(array_keys($counts), [array_values($counts)]);
        $this->info($dry ? 'Dry run - nothing changed.' : 'Done.');

        return self::SUCCESS;
    }

    /** `/storage/properties/1/x.jpg` or `https://host/storage/properties/1/x.jpg` -> `properties/1/x.jpg`; null when it is neither. */
    private function publicRelativePath(string $value): ?string
    {
        $pos = strpos($value, '/storage/');

        return $pos === false ? null : ltrim(substr($value, $pos + strlen('/storage/')), '/');
    }

    /** Does any other record still point at this public file (another photo row, a fault report photo, a work order photo)? */
    private function publicFileStillUsed(string $raw, int $exceptInspectionPhotoId): bool
    {
        foreach (['rental_fault_report_photos', 'rental_work_order_photos'] as $table) {
            if (DB::table($table)->where('storage_path', $raw)->exists()) {
                return true;
            }
        }

        return DB::table('rental_inspection_photos')->where('storage_path', $raw)->where('id', '!=', $exceptInspectionPhotoId)->exists();
    }
}
