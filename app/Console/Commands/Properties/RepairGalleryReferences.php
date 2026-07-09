<?php

namespace App\Console\Commands\Properties;

use App\Models\Property;
use App\Services\Images\PropertyImageGuard;
use App\Services\Images\PropertyThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Repairs gallery references that name a file which is not on disk.
 *
 * A dangling reference is not cosmetic: PrivateProperty fetches every photo BY
 * URL and rejects the ENTIRE UpdateListing when one 404s (PP120), so a single
 * dead reference silently blocks all further updates of that listing to the
 * portal. Property24 embeds bytes and skips missing files, which is why the
 * fault can sit undetected for days.
 *
 * Recovery, not just deletion. When a photo is rotated CoreX writes a new file
 * and unlinks the original; if the reference is then reverted (a stale tab
 * re-posting its old array — property 6060, 2026-07-06) the rotated file is
 * left orphaned on disk while the JSON names the deleted one. The rotated file
 * IS the photo. So for every dangling reference this command looks for an
 * unreferenced file in the same directory whose content matches the dangling
 * reference's surviving thumbnail, and repoints to it. Only when no match is
 * found is the reference removed.
 *
 * Safety: properties whose images are ALL missing are skipped by default. Those
 * are legacy imports whose files were never on this host (~531 of them); their
 * references are the only record that photos ever existed, and stripping them
 * would empty the gallery rather than repair it. Use --include-empty to force.
 */
class RepairGalleryReferences extends Command
{
    protected $signature = 'properties:repair-gallery-references
                            {--property= : Repair a single property by id}
                            {--apply : Persist changes (default is a dry run)}
                            {--include-empty : Also touch properties where every image is missing}';

    protected $description = 'Find and repair gallery references to files that no longer exist on disk';

    /** Image fields that can hold a gallery URL list. */
    private const LIST_FIELDS = [
        'gallery_images_json',
        'dawn_images_json',
        'noon_images_json',
        'dusk_images_json',
    ];

    /** Max mean-absolute-difference (0–255 scale) for two images to be "the same photo". */
    private const MATCH_TOLERANCE = 6.0;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $query = Property::withoutGlobalScopes()->whereNotNull('gallery_images_json');
        if ($id = $this->option('property')) {
            $query->whereKey((int) $id);
        }

        $scanned = 0;
        $repaired = 0;
        $repointed = 0;
        $removed = 0;
        $skippedEmpty = 0;

        $query->chunkById(200, function ($properties) use (
            $apply, &$scanned, &$repaired, &$repointed, &$removed, &$skippedEmpty
        ) {
            foreach ($properties as $property) {
                $scanned++;

                $gallery = array_values(array_filter((array) ($property->gallery_images_json ?? []), 'is_string'));
                $dangling = $this->danglingRefs($gallery);

                if (!$dangling) {
                    continue;
                }

                // Legacy import with no files at all — its references are the only
                // trace of the photos. Removing them destroys, not repairs.
                if (count($dangling) === count($gallery) && !$this->option('include-empty')) {
                    $skippedEmpty++;
                    continue;
                }

                $this->line("Property {$property->id}: " . count($dangling) . ' dangling of ' . count($gallery));

                $plan = [];
                foreach ($dangling as $deadUrl) {
                    $replacement = $this->findRecoveryCandidate($property, $deadUrl);
                    $plan[$deadUrl] = $replacement;

                    if ($replacement) {
                        $repointed++;
                        $this->info('   repoint ' . basename($deadUrl) . ' → ' . basename($replacement));
                    } else {
                        $removed++;
                        $this->warn('   remove  ' . basename($deadUrl) . ' (no recoverable file)');
                    }
                }

                if ($apply) {
                    $this->applyPlan($property, $plan);
                    $repaired++;
                }
            }
        });

        $this->newLine();
        $this->line("Scanned {$scanned} properties.");
        $this->line("Dangling references: {$repointed} recoverable, {$removed} unrecoverable.");
        if ($skippedEmpty) {
            $this->line("Skipped {$skippedEmpty} properties with no images on disk at all (use --include-empty to force).");
        }

        if (!$apply) {
            $this->newLine();
            $this->comment('Dry run — nothing was written. Re-run with --apply to persist.');
        } else {
            $this->info("Repaired {$repaired} properties.");
        }

        return self::SUCCESS;
    }

    /** CoreX-hosted references in the list whose file is missing from disk. */
    private function danglingRefs(array $urls): array
    {
        return array_values(array_filter(
            $urls,
            fn ($u) => PropertyImageGuard::isLocal($u) && !PropertyImageGuard::existsOnDisk($u)
        ));
    }

    /**
     * Find an unreferenced file in this property's directory that is the same
     * photo as the dangling reference. Identified by comparing against the
     * thumbnail that survives the original's deletion (thumbs are written on
     * upload and never removed by the rotator), across all four right-angle
     * orientations — a rotated file is the same photo, turned.
     */
    private function findRecoveryCandidate(Property $property, string $deadUrl): ?string
    {
        $deadRel = PropertyImageGuard::relativePath($deadUrl);
        if ($deadRel === null) {
            return null;
        }

        $thumbRel = dirname($deadRel) . '/' . PropertyThumbnailService::THUMB_DIR
            . '/' . pathinfo($deadRel, PATHINFO_FILENAME) . '.jpg';

        $disk = Storage::disk('public');
        if (!$disk->exists($thumbRel)) {
            return null; // nothing to compare against
        }

        $target = $this->signature($disk->path($thumbRel));
        if ($target === null) {
            return null;
        }

        $referenced = array_map('basename', $this->allReferences($property));

        $best = null;
        $bestScore = self::MATCH_TOLERANCE;

        foreach ($disk->files(dirname($deadRel)) as $candidateRel) {
            if (in_array(basename($candidateRel), $referenced, true)) {
                continue; // in use by this property already
            }

            foreach ([0, 90, 180, 270] as $degrees) {
                $sig = $this->signature($disk->path($candidateRel), $degrees);
                if ($sig === null) {
                    continue;
                }
                $score = $this->distance($target, $sig);
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = $candidateRel;
                }
            }
        }

        return $best ? '/storage/' . $best : null;
    }

    /** Every image URL referenced anywhere on the property. */
    private function allReferences(Property $property): array
    {
        $urls = [];
        foreach (self::LIST_FIELDS as $field) {
            foreach ((array) ($property->{$field} ?? []) as $u) {
                if (is_string($u)) {
                    $urls[] = $u;
                }
            }
        }
        $cats = (array) ($property->gallery_categories_json ?? []);
        array_walk_recursive($cats, function ($v) use (&$urls) {
            if (is_string($v) && str_contains($v, '/storage/')) {
                $urls[] = $v;
            }
        });

        return $urls;
    }

    /** 32×32 grayscale fingerprint, optionally rotated first. Null when unreadable. */
    private function signature(string $absPath, int $degrees = 0): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $bytes = @file_get_contents($absPath);
        $img = $bytes ? @imagecreatefromstring($bytes) : false;
        if (!$img instanceof \GdImage) {
            return null;
        }

        if ($degrees !== 0) {
            $rotated = @imagerotate($img, $degrees, 0);
            imagedestroy($img);
            if (!$rotated instanceof \GdImage) {
                return null;
            }
            $img = $rotated;
        }

        $side = 32;
        $small = imagecreatetruecolor($side, $side);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $side, $side, imagesx($img), imagesy($img));
        imagedestroy($img);

        $sig = [];
        for ($y = 0; $y < $side; $y++) {
            for ($x = 0; $x < $side; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $sig[] = ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3;
            }
        }
        imagedestroy($small);

        return $sig;
    }

    /** Mean absolute difference between two equal-length signatures. */
    private function distance(array $a, array $b): float
    {
        $total = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $total += abs($a[$i] - $b[$i]);
        }

        return $total / max(1, $n);
    }

    /**
     * @param  array<string, string|null>  $plan  dead URL → replacement URL (null = remove)
     */
    private function applyPlan(Property $property, array $plan): void
    {
        $updates = [];

        $rewrite = function (array $urls) use ($plan): array {
            $out = [];
            foreach ($urls as $u) {
                if (!is_string($u)) {
                    continue;
                }
                if (!array_key_exists($u, $plan)) {
                    $out[] = $u;
                    continue;
                }
                if ($plan[$u] !== null) {
                    $out[] = $plan[$u];
                }
                // null → drop
            }

            return array_values(array_unique($out));
        };

        foreach (self::LIST_FIELDS as $field) {
            $arr = $property->{$field};
            if (is_array($arr) && $arr) {
                $updates[$field] = $rewrite($arr);
            }
        }

        $cats = $property->gallery_categories_json;
        if (is_array($cats)) {
            if (!empty($cats['categories']) && is_array($cats['categories'])) {
                foreach ($cats['categories'] as $i => $cat) {
                    if (isset($cat['images']) && is_array($cat['images'])) {
                        $cats['categories'][$i]['images'] = $rewrite($cat['images']);
                    }
                }
            }
            if (!empty($cats['unsorted']) && is_array($cats['unsorted'])) {
                $cats['unsorted'] = $rewrite($cats['unsorted']);
            }
            $updates['gallery_categories_json'] = $cats;
        }

        $property->update($updates);
    }
}
