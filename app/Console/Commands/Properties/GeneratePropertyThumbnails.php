<?php

namespace App\Console\Commands\Properties;

use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\Images\PropertyThumbnailService;
use Illuminate\Console\Command;

/**
 * Backfill list-view thumbnails for existing property photos.
 *
 * Idempotent + resumable: skips any image whose thumbnail already exists, so a
 * re-run only fills gaps and an interrupted run continues where it left off.
 * Throttled for live/business-hours use — small chunks with a sleep between
 * them keep GD off the CPU in bursts. Run it politely with nice/ionice:
 *
 *   nice -n 15 ionice -c3 php artisan properties:generate-thumbnails
 *
 * Originals are never modified; thumbs land under properties/{id}/thumbs/.
 */
class GeneratePropertyThumbnails extends Command
{
    protected $signature = 'properties:generate-thumbnails
        {--chunk=25 : properties per chunk}
        {--sleep=1 : seconds to sleep between chunks (throttle)}
        {--force : regenerate even if a thumbnail already exists}
        {--property= : only this property id}';

    protected $description = 'Backfill small web thumbnails for property list views (idempotent, throttled).';

    public function handle(PropertyThumbnailService $thumbs): int
    {
        $force = (bool) $this->option('force');
        $sleep = max(0, (int) $this->option('sleep'));
        $chunk = max(1, (int) $this->option('chunk'));

        $query = Property::query()->withoutGlobalScope(AgencyScope::class)->orderBy('id');
        if ($only = $this->option('property')) {
            $query->whereKey((int) $only);
        }

        $properties = 0;
        $generated  = 0;
        $started     = microtime(true);

        $query->chunkById($chunk, function ($rows) use ($thumbs, $force, $sleep, &$properties, &$generated) {
            foreach ($rows as $property) {
                $properties++;
                $generated += $thumbs->generateForProperty($property, $force);
            }
            $this->getOutput()->write('.');
            if ($sleep > 0) {
                sleep($sleep); // be a good citizen during business hours
            }
        });

        $secs = round(microtime(true) - $started, 1);
        $this->newLine();
        $this->info("Done: {$properties} properties scanned, {$generated} thumbnails ensured in {$secs}s.");

        return self::SUCCESS;
    }
}
