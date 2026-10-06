<?php

namespace App\Console\Commands;

use App\Services\PlatformEsign\Agreement\AgreementReminders;
use Illuminate\Console\Command;

/** Expires lapsed Subscription Agreement links and sends the agency / countersign reminders (spec §11.14). Scheduled hourly. */
class PlatformEsignRemindAgreements extends Command
{
    protected $signature = 'platform-esign:remind-agreements {--dry-run : List what would be expired or reminded without changing or sending anything}';
    protected $description = 'Expire lapsed Subscription Agreement links and send agency / countersign reminders (Platform E-Sign)';

    public function handle(AgreementReminders $reminders): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $reminders->run($dry);
        foreach ($r['lines'] as $line) {
            $this->line($line);
        }
        $this->info(sprintf('%s%d expired, %d agency reminders, %d countersign reminders.', $dry ? '[dry run] ' : '', $r['expired'], $r['reminded'], $r['countersign_reminded']));

        return self::SUCCESS;
    }
}
