<?php

namespace App\Console\Commands;

use App\Services\P24\P24EmailParserService;
use App\Services\P24\P24ImapImportService;
use Illuminate\Console\Command;

class ImportP24Alerts extends Command
{
    protected $signature = 'p24:import';

    protected $description = 'Import Property24 alert emails via IMAP';

    public function handle(): int
    {
        $this->info('Starting P24 email import...');

        $service = new P24ImapImportService(new P24EmailParserService());
        $result = $service->import();

        if ($result['status'] === 'disabled') {
            $this->warn($result['message']);
            return 0;
        }

        if ($result['status'] === 'error') {
            $this->error($result['message']);
            return 1;
        }

        $stats = $result['stats'] ?? [];
        $this->info(sprintf(
            'Done! Processed: %d, New: %d, Updated: %d, Skipped: %d, Errors: %d',
            $stats['processed'] ?? 0,
            $stats['new'] ?? 0,
            $stats['updated'] ?? 0,
            $stats['skipped'] ?? 0,
            $stats['errors'] ?? 0,
        ));

        return 0;
    }
}
