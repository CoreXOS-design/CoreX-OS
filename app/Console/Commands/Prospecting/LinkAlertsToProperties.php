<?php

declare(strict_types=1);

namespace App\Console\Commands\Prospecting;

use App\Services\Prospecting\AlertPropertyLinker as Linker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * property-identity.md section 15, C1: tie every e-mail alert row that has no property file to its property file through the door.
 *
 *   php artisan alerts:link-properties --dry-run     # ALWAYS first: counts + samples, nothing written
 *   php artisan alerts:link-properties --apply       # then the real run (idempotent; only DERIVED links are written, nothing is merged or created)
 *
 * Refuses to run outside QA / local / testing until Johan orders it elsewhere.
 */
final class LinkAlertsToProperties extends Command
{
    protected $signature = 'alerts:link-properties
        {--dry-run : Count and report only; write nothing (the default when neither flag is given)}
        {--apply : Save the links}
        {--portal=all : p24, pp or all}
        {--agency= : Limit to one agency id}
        {--chunk=500 : Rows per batch}';

    protected $description = 'Link e-mail alert rows (Property24 / Private Property) to their property file by advert number (dry-run first)';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(Linker $linker): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This only runs on QA / local / testing until Johan orders it elsewhere (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }
        $portal = (string) $this->option('portal');
        $portals = $portal === 'all' ? array_keys(Linker::PORTALS) : [$portal];
        if (array_diff($portals, array_keys(Linker::PORTALS))) {
            $this->error('Unknown portal. Use p24, pp or all.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $agencyOpt = $this->option('agency') !== null && $this->option('agency') !== '' ? (int) $this->option('agency') : null;
        $chunk = max(100, (int) $this->option('chunk'));

        $this->line('alerts:link-properties - ' . ($apply ? 'APPLY' : 'DRY RUN'));
        foreach ($portals as $p) {
            $cfg = Linker::PORTALS[$p];
            $count = ['rows' => 0, 'already' => 0, 'linked' => 0, 'no_match' => 0, 'unreadable' => 0];
            $byAgency = [];
            $samples = [];

            $q = DB::table($cfg['table'])->whereNull('deleted_at')->when($agencyOpt, fn ($q) => $q->where('agency_id', $agencyOpt))->orderBy('id');
            $q->chunkById($chunk, function ($rows) use ($linker, $p, $cfg, $apply, &$count, &$byAgency, &$samples) {
                foreach ($rows as $r) {
                    $res = $linker->link($p, (int) $r->id, (int) $r->agency_id, (string) $r->{$cfg['number']}, $r->tracked_property_id ? (int) $r->tracked_property_id : null, $apply);
                    $count['rows']++;
                    $count[$res['result']]++;
                    if ($res['result'] === Linker::RESULT_LINKED) {
                        $byAgency[$r->agency_id] = ($byAgency[$r->agency_id] ?? 0) + 1;
                        if (count($samples) < 5) {
                            $samples[] = "{$res['canonical_ref']} -> property file {$res['tracked_property_id']} ({$res['confidence']}/{$res['method']})";
                        }
                    }
                }
            });

            $this->line('');
            $this->line(strtoupper($p) . ' alerts (' . $cfg['table'] . ')');
            $this->line("  rows read:           {$count['rows']}");
            $this->line("  already linked:      {$count['already']}");
            $this->line('  ' . ($apply ? 'linked now:' : 'would link:') . "         {$count['linked']}");
            $this->line("  no property file yet: {$count['no_match']}   (left alone; picked up once the advert is on a property file)");
            $this->line("  advert number unreadable: {$count['unreadable']}");
            foreach ($byAgency as $a => $n) {
                $this->line("  agency {$a}: {$n}");
            }
            foreach ($samples as $s) {
                $this->line("  e.g. {$s}");
            }
        }
        $this->line('');
        $this->line($apply ? 'Done. Only links were written; no property file was created or merged.' : 'Dry run: nothing was written. Run with --apply to save these links.');

        return self::SUCCESS;
    }
}
