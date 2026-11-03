<?php

namespace App\Console\Commands\RoadToSold;

use App\Services\RoadToSold\OutcomeDefinitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v3 (road-to-sold.md 22.7) - bring an agency's seeded outcomes up to the v3 defaults without touching its edits.
 * A row still exactly like the old seed is updated; a row the agency changed is left alone; the new outcomes are added. Dry run unless --apply.
 */
class ApplyOutcomeDefaults extends Command
{
    protected $signature = 'rts:apply-outcome-defaults {--agency= : Only this agency id} {--track=sales} {--apply : Write the changes (otherwise a dry run)}';

    protected $description = 'Road to Sold v3: upgrade untouched seeded outcomes to the new defaults and add the new ones';

    public function handle(OutcomeDefinitionService $defs): int
    {
        $apply = (bool) $this->option('apply');
        $track = (string) $this->option('track');
        $ids = DB::table('rts_outcome_definitions')->where('track', $track)->when($this->option('agency'), fn ($q, $a) => $q->where('agency_id', (int) $a))
            ->distinct()->pluck('agency_id');

        foreach ($ids as $agencyId) {
            $r = $defs->upgradeDefaults((int) $agencyId, $track, $apply);
            $this->line(sprintf('agency #%d: %s updated [%s]; %s added [%s]; left alone because the agency changed them [%s]',
                $agencyId, $apply ? count($r['updated']) : 'would be', implode(', ', $r['updated']), $apply ? count($r['added']) : 'would be', implode(', ', $r['added']), implode(', ', $r['kept'])));
        }
        $this->info($apply ? 'Done.' : 'Dry run - nothing was written. Add --apply to write.');

        return self::SUCCESS;
    }
}
