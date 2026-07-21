<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-267 / POPIA (audit 2026-07-21) — move existing property Drive files off the world-readable
 * PUBLIC disk onto the private LOCAL disk, so they are only ever served through the gated
 * PropertyFileController::download route.
 *
 * IDEMPOTENT and SAFE to re-run: it copies public → local, flips the `disk` column, and only then
 * deletes the public copy. A file already on local, or whose public copy is missing, is skipped.
 * Run deliberately (not auto-run on deploy) and verify a Drive download afterwards:
 *
 *     php artisan corex:move-property-files-to-local --dry-run   # preview
 *     php artisan corex:move-property-files-to-local             # migrate
 */
class MovePropertyFilesToLocalDisk extends Command
{
    protected $signature = 'corex:move-property-files-to-local {--dry-run : List what would move without changing anything}';

    protected $description = 'Move public-disk property Drive files to the private local disk (POPIA).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $docs = Document::query()
            ->where('disk', 'public')
            ->where('storage_path', 'like', 'properties/%/files/%')
            ->get();

        $this->info(($dry ? '[dry-run] ' : '') . "Found {$docs->count()} public property file(s).");

        $moved = 0;
        $skipped = 0;

        foreach ($docs as $doc) {
            $path = $doc->storage_path;

            if (! Storage::disk('public')->exists($path)) {
                $this->warn("skip (public copy missing): #{$doc->id} {$path}");
                $skipped++;
                continue;
            }

            if ($dry) {
                $this->line("would move: #{$doc->id} {$path}");
                $moved++;
                continue;
            }

            // Copy to local, flip the pointer, then remove the public copy — in that order, so a
            // crash never leaves the row pointing at a file that does not exist.
            $bytes = Storage::disk('public')->get($path);
            Storage::disk('local')->put($path, $bytes);
            $doc->forceFill(['disk' => 'local'])->save();
            Storage::disk('public')->delete($path);

            $this->line("moved: #{$doc->id} {$path}");
            $moved++;
        }

        $this->info(($dry ? '[dry-run] ' : '') . "Done. {$moved} moved, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
