<?php

namespace App\Console\Commands;

use App\Services\PlatformEsign\Agreement\AgreementReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Expires lapsed Subscription Agreement links and sends the agency / countersign reminders (spec §11.14). Scheduled hourly. */
class PlatformEsignRemindAgreements extends Command
{
    protected $signature = 'platform-esign:remind-agreements {--dry-run : List what would be expired or reminded without changing or sending anything}';
    protected $description = 'Expire lapsed Subscription Agreement links and send agency / countersign reminders (Platform E-Sign)';

    public function handle(AgreementReminders $reminders): int
    {
        $dry = (bool) $this->option('dry-run');
        // One run at a time, wherever it was started from (scheduler, a manual run, a second server): an overlapping run would remind twice.
        $lock = Cache::lock('platform-esign:remind-agreements', 900);
        if (!$lock->get()) {
            $this->warn('Another run of platform-esign:remind-agreements is already in progress — nothing done.');

            return self::SUCCESS;
        }
        try {
            $r = $reminders->run($dry);
        } finally {
            $lock->release();
        }
        foreach ($r['lines'] as $line) {
            $this->line($line);
        }
        $this->info(sprintf('%s%d expired, %d agency reminders, %d countersign reminders.', $dry ? '[dry run] ' : '', $r['expired'], $r['reminded'], $r['countersign_reminded']));

        return self::SUCCESS;
    }
}
