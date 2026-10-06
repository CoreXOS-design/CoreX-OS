<?php

namespace App\Console\Commands\Rentals;

use App\Services\Rentals\RentalCompletionService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-work-orders.md §17.10.8 — "silence = accepted". Every completion round still waiting for the tenant
 * past its response window (stored on the round when work was reported done, so a later settings change never moves it)
 * becomes `accepted_by_silence`; the work order's history says so and the responsible agent is told in-app. It NEVER
 * touches a disputed round. After the window the response link and the portal endpoint refuse with "The response
 * period has ended — please report a new fault" (a late complaint is a new fault report, not a reopening).
 *
 * Scheduled daily beside notifications:scan-rental-work-orders (routes/console.php).
 */
class SettleRentalCompletionRounds extends Command
{
    protected $signature = 'rentals:settle-completion-rounds';

    protected $description = 'Accept every tenant completion check that nobody answered inside its response window';

    public function handle(RentalCompletionService $completion): int
    {
        $settled = $completion->settleSilent();

        $this->info("Settled {$settled} completion round(s) as accepted — no tenant response inside the window.");

        return self::SUCCESS;
    }
}
