<?php

namespace App\Console\Commands;

use App\Models\MinionCaptureSettings;
use App\Services\Minion\MinionCaptureRunner;
use App\Support\Minion\MinionAlerts;
use Illuminate\Console\Command;

// AT-284 — P24 chrome-minion capture. Manual (--suburb / --town) or nightly (--cycle).
class MinionCapture extends Command
{
    protected $signature = 'minion:capture
        {--agency= : agency id (blank + --cycle = every enabled agency)}
        {--suburb= : a single p24_suburbs.id}
        {--town= : a p24 city/town name (all its ticked suburbs)}
        {--cycle : nightly least-recently-captured slice}
        {--by=manual : manual|schedule}';

    protected $description = 'AT-284: P24 chrome-minion capture — one suburb, one town, or the nightly cycle slice.';

    public function handle(MinionCaptureRunner $runner): int
    {
        $by        = (string) $this->option('by');
        $agencyOpt = $this->option('agency');
        $runs      = [];

        if ($this->option('suburb')) {
            $runs = [$runner->captureSuburb($this->agencyId($agencyOpt), (int) $this->option('suburb'), $by)];
        } elseif ($this->option('town')) {
            $runs = $runner->captureTown($this->agencyId($agencyOpt), (string) $this->option('town'), $by);
        } elseif ($this->option('cycle')) {
            $agencyIds = ($agencyOpt !== null && $agencyOpt !== '')
                ? [(int) $agencyOpt]
                : MinionCaptureSettings::where('enabled', true)->pluck('agency_id')->all();
            foreach ($agencyIds as $aid) {
                foreach ($runner->captureCycle((int) $aid, $by ?: 'schedule') as $r) {
                    $runs[] = $r;
                }
            }
        } else {
            $this->error('Specify --suburb=ID, --town=NAME, or --cycle.');
            return self::INVALID;
        }

        foreach ($runs as $r) {
            $this->line(sprintf(
                '[%s] %s  captured=%d new=%d updated=%d %s',
                $r->status, $r->area_label, $r->captured, $r->listings_new, $r->listings_updated,
                $r->status === 'failed' ? '(' . implode('; ', (array) ($r->failures_json ?? [])) . ')' : ''
            ));
        }

        $ok     = collect($runs)->where('status', 'ok')->count();
        $failed = collect($runs)->where('status', 'failed')->count();
        $this->info("minion:capture done — {$ok} ok, {$failed} failed, " . count($runs) . ' total.');

        if ($failed > 0) {
            collect($runs)->where('status', 'failed')->groupBy('agency_id')
                ->each(fn ($grp, $aid) => MinionAlerts::failures((int) $aid, $grp->all()));
        }

        return ($failed > 0 && $ok === 0) ? self::FAILURE : self::SUCCESS;
    }

    private function agencyId($opt): int
    {
        return ($opt !== null && $opt !== '') ? (int) $opt : 1; // default HFC
    }
}
