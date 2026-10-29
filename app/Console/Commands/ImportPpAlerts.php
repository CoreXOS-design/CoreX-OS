<?php

namespace App\Console\Commands;

use App\Services\PrivateProperty\PpEmailParserService;
use App\Services\PrivateProperty\PpImapImportService;
use Illuminate\Console\Command;

class ImportPpAlerts extends Command
{
    protected $signature = 'pp:import';

    protected $description = 'Import Private Property alert emails via IMAP (the twin of p24:import)';

    public function handle(): int
    {
        $this->info('Starting Private Property email import...');

        $result = (new PpImapImportService(new PpEmailParserService()))->import();

        if ($result['status'] === 'disabled') {
            $this->warn($result['message']);

            return 0;
        }
        if ($result['status'] === 'error') {
            $this->error($result['message']);

            return 1;
        }

        $s = $result['stats'] ?? [];
        $this->info(sprintf(
            'Done! Processed: %d, New: %d, Updated: %d, Skipped: %d, Unreadable: %d, Errors: %d',
            $s['processed'] ?? 0, $s['new'] ?? 0, $s['updated'] ?? 0, $s['skipped'] ?? 0, $s['unreadable'] ?? 0, $s['errors'] ?? 0,
        ));

        return 0;
    }
}
