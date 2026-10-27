<?php

namespace App\Console\Commands;

use App\Services\Desk\DeskSessionService;
use Illuminate\Console\Command;

/**
 * CoreX Desk - close sessions nobody joined in time and sessions whose agent tab has gone quiet (spec .ai/specs/corex-desk.md section 7.3).
 * The same sweep also runs lazily whenever sessions are read, so a box without a scheduler still behaves; this just tidies up.
 */
class DeskExpire extends Command
{
    protected $signature = 'desk:expire';

    protected $description = 'Close CoreX Desk sessions that expired or lost their connection';

    public function handle(DeskSessionService $desk): int
    {
        $this->info('Closed ' . $desk->expireStale() . ' Desk session(s).');

        return self::SUCCESS;
    }
}
