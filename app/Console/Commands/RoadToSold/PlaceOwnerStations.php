<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\OwnerStationService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4 (road-to-sold.md 23.6): one-time back-fill. Every open belt record that is before the Owner station but whose property file
 * already has its owner linked (and the deed, when the agency needs one) moves to Owner found.
 *
 *   php artisan rts:place-owner-stations --agency=1                dry run
 *   php artisan rts:place-owner-stations --agency=1 --apply        do it, with a row journal in /mnt/HC_Volume_103099143/mic-unwind/
 *   php artisan rts:place-owner-stations --undo=<journal.jsonl>    put each record back on the stage the journal recorded
 */
class PlaceOwnerStations extends Command
{
    protected $signature = 'rts:place-owner-stations {--agency= : Agency id (required)} {--apply} {--undo=} {--journal-dir=/mnt/HC_Volume_103099143/mic-unwind}';

    protected $description = 'Road to Sold v4: move belt records whose owner is already linked to Owner found (dry run first; journalled; reversible)';

    public function handle(OwnerStationService $owner, ProspectService $prospects): int
    {
        if ($this->option('undo')) {
            return $this->undo((string) $this->option('undo'));
        }
        $agencyId = (int) $this->option('agency');
        if ($agencyId <= 0) {
            $this->error('--agency=<id> is required.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $fh = null;
        $path = null;
        if ($apply) {
            @mkdir((string) $this->option('journal-dir'), 0775, true);
            $path = rtrim((string) $this->option('journal-dir'), '/') . '/rts-place-owner-stations-agency' . $agencyId . '-' . now()->format('Ymd-His') . '.jsonl';
            $fh = fopen($path, 'ab');
        }
        $would = 0;
        $moved = 0;
        $byFrom = [];
        RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', 'sales')->whereNull('closed_at')->whereNull('released_at')->whereNull('deleted_at')
            ->orderBy('id')->chunkById(300, function ($rows) use ($owner, $prospects, $apply, $fh, &$would, &$moved, &$byFrom, $agencyId) {
                foreach ($rows as $p) {
                    if (ProspectStages::order((string) $p->stage) >= ProspectStages::order(ProspectStages::OWNER_FOUND) || ! $owner->ownerFound($agencyId, (int) $p->tracked_property_id)) {
                        continue;
                    }
                    $would++;
                    $byFrom[$p->stage] = ($byFrom[$p->stage] ?? 0) + 1;
                    if ($apply) {
                        $from = $p->stage;
                        if ($prospects->advance($p, ProspectStages::OWNER_FOUND, null, ['event_class' => 'rts:place-owner-stations', 'note' => 'The owner was already linked'])) {
                            $moved++;
                            fwrite($fh, json_encode(['prospect_id' => (int) $p->id, 'from' => $from]) . "\n");
                        }
                    }
                }
            });
        if ($fh) {
            fclose($fh);
        }
        $this->info(($apply ? '[APPLIED] ' : '[DRY RUN] ') . "agency $agencyId: " . ($apply ? "$moved moved" : "$would would move") . ' to Owner found; from: ' . json_encode($byFrom));
        $this->line($apply ? "  journal: $path   undo: php artisan rts:place-owner-stations --undo=$path" : '  (nothing written - add --apply)');

        return self::SUCCESS;
    }

    private function undo(string $file): int
    {
        if (! is_file($file)) {
            $this->error("Journal not found: $file");

            return self::FAILURE;
        }
        $n = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $j = json_decode($line, true);
            if (is_array($j) && ! empty($j['prospect_id']) && ! empty($j['from'])) {
                DB::table('rts_prospects')->where('id', $j['prospect_id'])->where('stage', ProspectStages::OWNER_FOUND)->update(['stage' => $j['from']]);
                $n++;
            }
        }
        $this->info("Undone: $n record(s) put back (their history rows stay).");

        return self::SUCCESS;
    }
}
