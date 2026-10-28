<?php

namespace App\Console\Commands;

use App\Services\AttorneyPortal\AttorneyPortalNotifier;
use Illuminate\Console\Command;

/** .ai/specs/attorney-portal.md 15 - the daily "new documents" digest to attorneys. Safe to run more than once a day (one per deal per day). */
class AttorneyPortalNotifyNewDocuments extends Command
{
    protected $signature = 'attorney-portal:notify-new-documents {--agency= : only this agency id} {--dry-run : count, send nothing}';

    protected $description = 'Email attorneys one note per deal when new documents in their portal set were filed (once a day per deal)';

    public function handle(AttorneyPortalNotifier $notifier): int
    {
        $r = $notifier->run($this->option('agency') ? (int) $this->option('agency') : null, (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? '[dry-run] would send ' : 'sent ') . $r['sent'] . ' note(s) across ' . $r['considered'] . ' deal(s) checked.');

        return self::SUCCESS;
    }
}
