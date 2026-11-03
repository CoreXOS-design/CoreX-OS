<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use Illuminate\Console\Command;

/**
 * MIC 5.2 (AT-519): read-only report of every raw property-type text per table, its canonical key, and what is unmapped.
 *   php artisan mic:property-types-report [--agency=1]
 */
final class MicPropertyTypesReport extends Command
{
    protected $signature = 'mic:property-types-report {--agency= : Limit to one agency_id}';

    protected $description = 'MIC 5.2: raw property-type values per table with their canonical key; lists unmapped values. Read-only.';

    public function handle(PropertyTypeResolver $resolver): int
    {
        $agency = $this->option('agency') !== null && $this->option('agency') !== '' ? (int) $this->option('agency') : null;
        $unmapped = 0;
        foreach ($resolver->report($agency) as $source => $rows) {
            $this->info($source);
            foreach ($rows as $r) {
                $label = $r['raw'] === '' ? '<empty>' : $r['raw'];
                $key = $r['type_key'] ?? ($r['raw'] === '' ? '- (blank)' : 'UNMAPPED');
                if ($r['type_key'] === null && $r['raw'] !== '') {
                    $unmapped += $r['rows'];
                }
                $this->line(sprintf('  %-36s %8d  -> %s', $label, $r['rows'], $key));
            }
        }
        $this->line('');
        $this->line("Unmapped (non-blank) rows in total: {$unmapped}");

        return self::SUCCESS;
    }
}
