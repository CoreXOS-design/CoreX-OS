<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\Properties\LegacySpacesJsonConverter;
use Illuminate\Console\Command;

/**
 * Stage 2 conversion — Johan: "current rental stock cannot be dumped, it
 * needs to be converted." Multi-agency by design: --agency scopes to one,
 * omitted runs across all. Idempotent: LegacySpacesJsonConverter::
 * needsConversion() skips anything already new-format or already carrying
 * a backup, so a second run touches nothing already converted.
 */
class ConvertLegacySpacesJson extends Command
{
    protected $signature = 'rentals:convert-legacy-spaces-json
        {--agency= : Limit to one agency_id. Omit to run across all agencies.}
        {--dry-run : Report what would change per property, write nothing.}
        {--limit= : Cap how many properties are processed this run.}';

    protected $description = 'Convert old flat spaces_json (and fully-null spaces_json carrying only legacy beds/baths/garages columns) into the current {spaces:[...], features:{...}} shape.';

    public function handle(LegacySpacesJsonConverter $converter): int
    {
        $query = Property::query();
        if ($agencyId = $this->option('agency')) {
            $query->where('agency_id', $agencyId);
        }
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $dryRun = (bool) $this->option('dry-run');
        $converted = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(200, function ($properties) use ($converter, $dryRun, &$converted, &$skipped) {
            foreach ($properties as $property) {
                $result = $converter->convert($property, $dryRun);
                if ($result['skipped']) {
                    $skipped++;
                    continue;
                }
                $converted++;
                if ($dryRun) {
                    $this->line("[DRY RUN] #{$property->id} (agency {$property->agency_id}) -> " . json_encode($result['new']));
                }
            }
        });

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Converted: {$converted}. Skipped (already new format or already converted): {$skipped}.");

        return self::SUCCESS;
    }
}
