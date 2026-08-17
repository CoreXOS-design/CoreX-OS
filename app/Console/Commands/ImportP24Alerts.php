<?php

namespace App\Console\Commands;

use App\Services\P24\P24EmailParserService;
use App\Services\P24\P24ImapImportService;
use Illuminate\Console\Command;

class ImportP24Alerts extends Command
{
    protected $signature = 'p24:import';

    protected $description = 'Import Property24 alert emails via IMAP, once per agency with its own active mailbox configured (#3 per-agency)';

    public function handle(): int
    {
        $this->info('Starting P24 email import (per-agency)...');

        $service = new P24ImapImportService(new P24EmailParserService());
        $result = $service->importAllAgencies();

        if ($result['status'] === 'disabled') {
            $this->warn($result['message']);
            return 0;
        }

        $hadError = false;

        foreach ($result['agencies'] as $entry) {
            $agencyId = $entry['agency_id'];
            $r = $entry['result'];

            if ($r['status'] === 'error') {
                $this->error("Agency {$agencyId}: {$r['message']}");
                $hadError = true;
                continue;
            }

            $stats = $r['stats'] ?? [];
            $this->info(sprintf(
                'Agency %d: Processed: %d, New: %d, Updated: %d, Skipped: %d, Errors: %d',
                $agencyId,
                $stats['processed'] ?? 0,
                $stats['new'] ?? 0,
                $stats['updated'] ?? 0,
                $stats['skipped'] ?? 0,
                $stats['errors'] ?? 0,
            ));
        }

        // A single agency's broken mailbox never fails the whole run (they're
        // independent per-agency polls) — but surface a non-zero exit so a
        // failing agency's cron output still gets noticed.
        return $hadError ? 1 : 0;
    }
}
