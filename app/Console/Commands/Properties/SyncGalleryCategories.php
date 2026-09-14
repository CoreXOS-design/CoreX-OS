<?php

namespace App\Console\Commands\Properties;

use App\Models\Property;
use Illuminate\Console\Command;

/**
 * One-off backfill for the gallery invariant: every URL in gallery_images_json
 * appears exactly once in gallery_categories_json (a category or `unsorted`).
 *
 * Until 2026-09-14 the web create form wrote a listing's photos into
 * gallery_images_json and never touched gallery_categories_json, and the web
 * edit form only filed new uploads when an image_category was sent. The
 * mobile app builds its room-by-room gallery from gallery_categories_json
 * ALONE, so every such listing read "0 photos" in the app while its gallery
 * was full on the web. The writers now keep the two columns in step
 * (Property::syncGalleryCategories); this command repairs the rows written
 * before they did.
 *
 * Idempotent: a second run changes 0 rows. Each write happens under the same
 * row lock the mobile upload uses (Property::syncGalleryCategoriesLocked), so
 * running it against a live system cannot lose a concurrent upload.
 */
class SyncGalleryCategories extends Command
{
    protected $signature = 'properties:sync-gallery-categories
                            {--dry-run : Report what would change without writing}
                            {--property= : Sync a single property by id}
                            {--chunk=200 : Rows per chunk}';

    protected $description = 'File every gallery_images_json photo that is missing from gallery_categories_json into unsorted (and drop stale category entries)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(1, (int) $this->option('chunk'));

        $query = Property::withoutGlobalScopes()
            ->whereNotNull('gallery_images_json');

        if ($id = $this->option('property')) {
            $query->whereKey((int) $id);
        }

        $scanned = 0;
        $changed = 0;
        $filed   = 0;
        $dropped = 0;

        $query->chunkById($chunk, function ($properties) use ($dryRun, &$scanned, &$changed, &$filed, &$dropped) {
            foreach ($properties as $property) {
                $scanned++;

                $before = $this->filedKeys($property->gallery_categories_json);

                // Preview in memory first so the dry run and the verbose line
                // both describe the actual delta without touching the row.
                $preview = clone $property;
                if (! $preview->syncGalleryCategories()) {
                    continue;
                }

                $after   = $this->filedKeys($preview->gallery_categories_json);
                $total   = count(array_filter((array) $property->gallery_images_json, 'is_string'));
                $added   = count(array_diff_key($after, $before));
                $removed = count(array_diff_key($before, $after));

                $filed   += $added;
                $dropped += $removed;

                if ($dryRun) {
                    $changed++;
                    $this->line("Property {$property->id}: would file {$added}, drop {$removed} (gallery holds {$total})");
                    continue;
                }

                if ($property->syncGalleryCategoriesLocked()) {
                    $changed++;
                    $this->line("Property {$property->id}: filed {$added}, dropped {$removed} (gallery holds {$total})");
                }
            }
        });

        $this->newLine();
        $this->line("Scanned {$scanned} properties.");
        $this->line(($dryRun ? 'Would change ' : 'Changed ') . "{$changed} rows ({$filed} photos filed into unsorted, {$dropped} stale entries dropped).");

        if ($dryRun) {
            $this->comment('Dry run — nothing was written. Re-run without --dry-run to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * The set of image keys a categories structure currently files
     * (categories + unsorted), keyed by Property::imageMatchKey.
     *
     * @return array<string, true>
     */
    private function filedKeys(mixed $cats): array
    {
        if (! is_array($cats)) {
            return [];
        }

        $keys = [];
        $take = function (mixed $urls) use (&$keys): void {
            foreach ((array) $urls as $u) {
                if (is_string($u) && trim($u) !== '') {
                    $keys[Property::imageMatchKey($u)] = true;
                }
            }
        };

        $take($cats['unsorted'] ?? []);
        foreach ((array) ($cats['categories'] ?? []) as $cat) {
            $take(is_array($cat) ? ($cat['images'] ?? []) : []);
        }

        return $keys;
    }
}
