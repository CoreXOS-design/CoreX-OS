<?php

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingSearch;
use App\Services\Prospecting\SuburbReconcileService;
use Illuminate\Console\Command;

/**
 * MIC SUBURB RECONCILE — manual trigger.
 *
 * The productionised trigger is automatic: the Chrome capture extension flags its final
 * batch `capture_complete` once it has walked every page of a suburb cleanly, and the import
 * runs the reconcile then. This command is the human-operated equivalent for testing or a
 * one-off: point it at a ProspectingSearch that you KNOW captured the whole suburb, and it
 * retires the listings gone from that suburb. Because a human asserts completeness here, it
 * works even for a capture made by an older extension that didn't send the flag.
 *
 * SAFEGUARD: never point this at a partial capture — that is the one way to wrongly retire
 * listings merely on a later page. Use --dry-run first to see exactly what would be retired.
 */
class ReconcileSuburbCapture extends Command
{
    protected $signature = 'prospecting:reconcile-suburb
                            {--search= : ProspectingSearch id of a COMPLETE suburb capture to reconcile against}
                            {--dry-run : Report what would be retired without writing}';

    protected $description = 'Retire active listings in a suburb that a complete capture did not include (soft; keeps rows)';

    public function handle(SuburbReconcileService $service): int
    {
        $searchId = (int) $this->option('search');
        if (! $searchId) {
            $this->error('Pass --search=<ProspectingSearch id> (a COMPLETE suburb capture).');
            return self::FAILURE;
        }

        $search = ProspectingSearch::find($searchId);
        if (! $search) {
            $this->error("ProspectingSearch #{$searchId} not found.");
            return self::FAILURE;
        }

        $this->info("Reconciling against search #{$search->id} — \"{$search->search_description}\" ({$search->portal_source}, agency {$search->agency_id}, captured {$search->captured_at}).");

        $result = $service->reconcile(
            (int) $search->agency_id,
            (string) $search->portal_source,
            $search,
            (bool) $this->option('dry-run'),
        );

        if ($result['skipped_reason'] && $result['retired'] === 0 && empty($result['suburbs'])) {
            $this->warn('Skipped: ' . $result['skipped_reason']);
            return self::SUCCESS;
        }

        $this->line('  Suburb(s): ' . implode(', ', $result['suburbs']));
        $this->line('  Present in this capture: ' . $result['present']);
        $this->line(($this->option('dry-run') ? '  WOULD retire' : '  Retired') . ': ' . $result['retired'] . ' listing(s) (soft — is_active=false + portal_status=withdrawn, rows kept)');

        return self::SUCCESS;
    }
}
