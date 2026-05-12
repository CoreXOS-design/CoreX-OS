<?php

namespace App\Console\Commands;

use App\Services\Prospecting\ProspectingStockMatchService;
use Illuminate\Console\Command;

class MatchProspectingStock extends Command
{
    protected $signature = 'prospecting:match-stock {--agency= : Agency ID to match (default: all)}';
    protected $description = 'Match prospecting listings against agency property stock';

    public function handle(ProspectingStockMatchService $service): int
    {
        $agencyId = $this->option('agency');

        if ($agencyId) {
            $this->info("Matching prospects for agency #{$agencyId}...");
            $result = $service->recomputeAllForAgency((int) $agencyId);
            $this->info("Done. Total: {$result['total']}, Matched: {$result['matched']}, Unmatched: {$result['unmatched']}");
        } else {
            $agencyIds = \DB::table('agencies')->pluck('id');
            foreach ($agencyIds as $id) {
                $this->info("Agency #{$id}...");
                $result = $service->recomputeAllForAgency($id);
                $this->line("  Total: {$result['total']}, Matched: {$result['matched']}, Unmatched: {$result['unmatched']}");
            }
        }

        return self::SUCCESS;
    }
}
