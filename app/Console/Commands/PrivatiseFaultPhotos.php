<?php

namespace App\Console\Commands;

use App\Services\Images\PrivateImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Security audit D14 - the pictures tenants and owners ALREADY sent through the portal sit on the public disk at permanent /storage
 * addresses. New uploads go to the private disk ({@see PrivateImageService::storeUpload()}); this moves the existing ones, once.
 *
 * What it moves: a fault-report photo with NO staff uploader (that is what a portal upload is - the same rule the tenant's own view
 * uses), whose file is `properties/{id}/{name}.jpg|png|webp` on the public disk. Audit 2026-10-20 adds the other pictures a tenant or the
 * crew sends that used to land on the public disk: a work-order photo of type `dispute` (the tenant's "not fixed" photos), a `crew_receipt`
 * (office evidence of a payment), and a tenant progress photo (uploaded_via = tenant, written from 2026-10-20 on - older tenant progress
 * photos carry no marker that tells them from a contractor's upload, so they are left alone).
 *
 * What it will NOT touch (reported as "shared"): a file that anything else also points at - an inspection or inventory photo, a
 * property gallery / valve / DB-board picture, a fault photo a member of staff uploaded, a work-order photo that is neither a plain
 * `reported` copy of the fault photo nor one of the kinds above. The only other rows that move with a file are those exact "same stored
 * picture" copies (fault photos with no uploader, work-order photos of the movable kinds), because they are the same evidence and must keep resolving.
 *
 * Order per file, so a crash can never leave a row pointing at nothing: copy to the private disk -> verify (size) -> repoint the rows
 * -> remove the old public file and its derived copies (the regenerable list thumbnail and portal-img caches, never a record).
 * Idempotent: a row already `private:` is not selected, a file already moved is not found twice. Safe by default: it only LISTS what
 * it would do unless `--force` is given.
 *
 *     php artisan rentals:privatise-fault-photos                 # preview (same as --dry-run)
 *     php artisan rentals:privatise-fault-photos --agency=3 --limit=50 --force
 */
class PrivatiseFaultPhotos extends Command
{
    protected $signature = 'rentals:privatise-fault-photos {--dry-run : Only list what would move (this is the default)} {--force : Actually move the files} {--agency= : Only this agency id} {--limit= : Move at most this many files}';

    protected $description = 'Move tenant/owner fault-photo files from the public disk to the private disk and repoint their rows (security audit D14).';

    public function handle(PrivateImageService $images): int
    {
        $dry = ! $this->option('force') || (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null && $this->option('limit') !== '' ? max(1, (int) $this->option('limit')) : null;
        $tag = $dry ? '[dry-run] ' : '';

        $rows = DB::table('rental_fault_report_photos')
            ->whereNull('uploaded_by_user_id')
            ->where('storage_path', 'not like', PrivateImageService::PREFIX . '%')
            ->when($this->option('agency') !== null && $this->option('agency') !== '', fn ($q) => $q->where('agency_id', (int) $this->option('agency')))
            ->orderBy('id')
            ->get(['id', 'agency_id', 'storage_path']);

        // one file may be pointed at by several rows: work per file
        $files = [];
        foreach ($rows as $row) {
            $rel = $images->legacyPublicRel($row->storage_path);
            if ($rel === null || ! preg_match('#^properties/(\d+)/[^/]+\.(?:jpe?g|png|webp)$#i', $rel)) {
                continue;   // not a plain property-folder picture: not ours to move
            }
            $files[$rel][] = $row;
        }

        // the work-order pictures a tenant / the crew sent (never a contractor's or the office's own upload)
        $woRows = DB::table('rental_work_order_photos')
            ->where('storage_path', 'not like', PrivateImageService::PREFIX . '%')
            ->where(fn ($q) => $this->ownKindScope($q))
            ->when($this->option('agency') !== null && $this->option('agency') !== '', fn ($q) => $q->where('agency_id', (int) $this->option('agency')))
            ->orderBy('id')
            ->get(['id', 'agency_id', 'storage_path']);
        foreach ($woRows as $row) {
            $rel = $images->legacyPublicRel($row->storage_path);
            if ($rel === null || ! preg_match('#^properties/(\d+)/[^/]+\.(?:jpe?g|png|webp)$#i', $rel)) {
                continue;
            }
            $files[$rel][] = $row;
        }

        $this->info($tag . 'Found ' . count($files) . ' public portal / crew picture file(s).');

        $moved = 0;
        $skipped = 0;
        foreach ($files as $rel => $faultRows) {
            if ($limit !== null && $moved >= $limit) {
                break;
            }

            $why = $this->whyNotMovable($images, $rel, $faultRows);
            if ($why !== null) {
                $this->warn("skip ({$why}): {$rel}");
                Log::info('rentals:privatise-fault-photos skipped', ['file' => $rel, 'why' => $why]);
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line("would move: {$rel} (" . count($faultRows) . ' row(s))');
                $moved++;

                continue;
            }

            if ($this->move($images, $rel, $faultRows)) {
                $moved++;
            } else {
                $skipped++;
            }
        }

        $this->info($tag . "Done. {$moved} moved, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** Work-order photos a tenant or the crew sent: the tenant's dispute photos, a crew receipt, a tenant progress photo. */
    private function ownKindScope($q)
    {
        return $q->whereIn('photo_type', ['dispute', 'crew_receipt'])->orWhere('uploaded_via', 'tenant');
    }

    private function isOwnKind(object $row): bool
    {
        return in_array($row->photo_type, ['dispute', 'crew_receipt'], true) || $row->uploaded_via === 'tenant';
    }

    /** null = fine to move; otherwise the plain reason it is left alone. */
    private function whyNotMovable(PrivateImageService $images, string $rel, array $faultRows): ?string
    {
        $public = Storage::disk('public');
        if (! $public->exists($rel)) {
            return 'public file missing';
        }
        $target = PrivateImageService::DIR . '/' . $rel;
        $private = Storage::disk(PrivateImageService::DISK);
        if ($private->exists($target) && $private->size($target) !== $public->size($rel)) {
            return 'a different private file is already there';
        }

        // every fault-photo row for this file, whoever uploaded it
        $sameFile = fn (?string $stored): bool => $images->legacyPublicRel($stored) === $rel;
        $faultAll = DB::table('rental_fault_report_photos')->where('storage_path', 'like', '%' . $rel)->get(['id', 'agency_id', 'uploaded_by_user_id', 'storage_path'])
            ->filter(fn ($r) => $sameFile($r->storage_path));
        if ($faultAll->contains(fn ($r) => $r->uploaded_by_user_id !== null)) {
            return 'shared: a staff-uploaded fault photo uses it';
        }

        // other tables that may point at the same file
        foreach (['rental_inspection_photos', 'rental_inventory_photos'] as $table) {
            if (DB::table($table)->where('storage_path', 'like', '%' . $rel)->get(['storage_path'])->contains(fn ($r) => $sameFile($r->storage_path))) {
                return "shared: {$table}";
            }
        }
        $wo = DB::table('rental_work_order_photos')->where('storage_path', 'like', '%' . $rel)->get(['id', 'agency_id', 'photo_type', 'uploaded_via', 'storage_path'])
            ->filter(fn ($r) => $sameFile($r->storage_path));
        $agencyIds = $faultAll->pluck('agency_id')->unique();
        $movableWo = fn ($r): bool => ($r->photo_type === 'reported' && $agencyIds->contains($r->agency_id)) || $this->isOwnKind($r);
        if ($wo->contains(fn ($r) => ! $movableWo($r))) {
            return 'shared: a work-order photo that is not the reported copy';
        }

        // the property's own pictures (gallery JSON, valve and DB-board photos) - only that property's row can name it
        preg_match('#^properties/(\d+)/#', $rel, $m);
        $property = DB::table('properties')->where('id', (int) $m[1])->first();
        if ($property) {
            foreach ((array) $property as $column => $value) {
                if (is_string($value) && $value !== '' && str_contains($value, $rel)) {
                    return "shared: properties.{$column}";
                }
            }
        }

        return null;
    }

    private function move(PrivateImageService $images, string $rel, array $faultRows): bool
    {
        $public = Storage::disk('public');
        $private = Storage::disk(PrivateImageService::DISK);
        $target = PrivateImageService::DIR . '/' . $rel;
        $ref = PrivateImageService::PREFIX . $target;

        $mtime = (int) @filemtime($public->path($rel));
        $size = $public->size($rel);

        // 1. copy (streamed) and 2. verify
        if (! $private->exists($target)) {
            $in = $public->readStream($rel);
            if (! is_resource($in) || $private->writeStream($target, $in) === false) {
                $this->error("FAILED to copy: {$rel}");
                Log::warning('rentals:privatise-fault-photos copy failed', ['file' => $rel]);

                return false;
            }
            if (is_resource($in)) {
                fclose($in);
            }
        }
        if (! $private->exists($target) || $private->size($target) !== $size || $size < 1) {
            $this->error("FAILED to verify the copy, left in place: {$rel}");
            Log::warning('rentals:privatise-fault-photos verify failed', ['file' => $rel]);

            return false;
        }

        // 3. repoint every row that is the same stored picture
        DB::transaction(function () use ($images, $rel, $ref) {
            foreach (DB::table('rental_fault_report_photos')->where('storage_path', 'like', '%' . $rel)->get(['id', 'storage_path']) as $r) {
                if ($images->legacyPublicRel($r->storage_path) === $rel) {
                    DB::table('rental_fault_report_photos')->where('id', $r->id)->update(['storage_path' => $ref]);
                }
            }
            foreach (DB::table('rental_work_order_photos')->where(fn ($q) => $q->where('photo_type', 'reported')->orWhere(fn ($o) => $this->ownKindScope($o)))
                ->where('storage_path', 'like', '%' . $rel)->get(['id', 'storage_path']) as $r) {
                if ($images->legacyPublicRel($r->storage_path) === $rel) {
                    DB::table('rental_work_order_photos')->where('id', $r->id)->update(['storage_path' => $ref]);
                }
            }
        });

        // 4. only now remove the public original and the regenerable public copies made from it
        $public->delete($rel);
        $derived = [dirname($rel) . '/thumbs/' . pathinfo($rel, PATHINFO_FILENAME) . '.jpg'];
        foreach ([480, 1600] as $edge) {
            $derived[] = 'portal-img/' . $edge . '/' . substr(sha1($rel . '|' . $mtime . '|' . $edge), 0, 24) . '.jpg';
        }
        foreach ($derived as $copy) {
            if ($public->exists($copy)) {
                $public->delete($copy);
            }
        }

        $this->line("moved: {$rel} -> {$ref} (" . count($faultRows) . ' fault photo row(s))');
        Log::info('rentals:privatise-fault-photos moved', ['file' => $rel, 'to' => $ref, 'rows' => count($faultRows)]);

        return true;
    }
}
