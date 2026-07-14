<?php

declare(strict_types=1);

namespace App\Console\Commands\Properties;

use App\Models\Property;
use App\Services\Properties\PropertyAddressReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-266 — reconcile properties whose `address` string and structured address
 * columns have drifted apart.
 *
 * REPORT-FIRST BY DEFAULT. Running this command changes nothing: it prints the
 * proposed address for every drifted row and exits. Data is written only with
 * --apply, and only for rows the reconciler is confident about — REVIEW rows are
 * never touched by a machine, they go to a human.
 *
 * --apply writes a JSON snapshot of every before-value to storage/app first, so
 * any run is reversible with --rollback=<file>.
 *
 * Sequencing that matters: PropertyObserver now DERIVES `address` from the
 * structured columns on every save. On a row whose structured columns are still
 * polluted, the next save would therefore push the pollution INTO the address.
 * So this reconciliation must run on a host BEFORE (or with) that observer change
 * reaching it — it cleans the parts, and once the parts are clean the derivation
 * is safe forever.
 */
final class ReconcilePropertyAddresses extends Command
{
    protected $signature = 'corex:reconcile-property-addresses
        {--apply : Write the proposed values (default is report-only)}
        {--agency= : Restrict to one agency}
        {--ids= : Comma-separated property ids}
        {--limit=25 : How many proposals to print (0 = all)}
        {--rollback= : Restore a previous run from its JSON snapshot}';

    protected $description = 'AT-266 — report (and optionally repair) properties whose address and structured columns disagree';

    public function handle(PropertyAddressReconciler $reconciler): int
    {
        if ($file = $this->option('rollback')) {
            return $this->rollback((string) $file);
        }

        $apply = (bool) $this->option('apply');

        $query = Property::withoutGlobalScopes()->whereNull('deleted_at');
        if ($agency = $this->option('agency')) {
            $query->where('agency_id', (int) $agency);
        }
        if ($ids = $this->option('ids')) {
            $query->whereIn('id', array_filter(array_map('intval', explode(',', (string) $ids))));
        }

        $drifted = [];
        $review  = [];

        foreach ($query->cursor() as $property) {
            $r = $reconciler->analyse($property);
            if ($r['status'] === PropertyAddressReconciler::OK) {
                continue;
            }
            $r['id'] = $property->id;
            $r['status'] === PropertyAddressReconciler::REVIEW ? $review[] = $r : $drifted[] = $r;
        }

        if (empty($drifted) && empty($review)) {
            $this->info('Every property address is coherent with its structured columns. Nothing to do.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line(sprintf('<comment>%d</comment> repairable, <comment>%d</comment> need a human.',
            count($drifted), count($review)));

        // Break it down by rule — "2,139 rows" is not a thing anyone can approve.
        // Knowing that most are a benign re-compose and only a handful are real
        // corruption repairs IS.
        $byRule = [];
        foreach ($drifted as $r) {
            $byRule[$r['rule']] = ($byRule[$r['rule']] ?? 0) + 1;
        }
        arsort($byRule);
        $this->line('');
        foreach ($byRule as $rule => $count) {
            $this->line(sprintf('  %-18s %5d   %s', $rule, $count, match ($rule) {
                'recompose'        => 'parts are sound — the display string was merely stale (enrichment, not repair)',
                'scheme-in-street' => 'the scheme name was sitting in the street-name box',
                'newline-glue'     => 'a single-line input deleted the line break',
                default            => '',
            }));
        }
        $this->line('');

        $limit = (int) $this->option('limit');
        $show  = $limit > 0 ? $limit : count($drifted);   // --limit=0 prints all
        foreach (array_slice($drifted, 0, $show) as $r) {
            $this->renderRow($r);
        }
        if (count($drifted) > $show) {
            $this->line(sprintf('  … and %d more (--limit=0 for all)', count($drifted) - $show));
            $this->line('');
        }

        if (!empty($review)) {
            $this->line('');
            $this->warn('NEEDS REVIEW — broken, but repairing it would mean guessing. Not touched by --apply:');
            foreach ($review as $r) {
                $this->line(sprintf('  <fg=yellow>#%d</> %s', $r['id'], $r['reason']));
                $this->line(sprintf('       address    : %s', json_encode($r['before']['address'])));
                $this->line(sprintf('       parts      : %s', $this->partsLine($r['before'])));
            }
        }

        if (!$apply) {
            $this->line('');
            $this->info('REPORT ONLY — nothing was written. Re-run with --apply to write the repairable rows.');
            return self::SUCCESS;
        }

        // Reversible: snapshot every before-value before touching anything.
        $stamp = $this->laravel['config']->get('app.reconcile_stamp') ?: now()->format('Ymd-His');
        $path  = "at266/reconcile-{$stamp}.json";
        Storage::put($path, json_encode(array_map(
            fn ($r) => ['id' => $r['id'], 'before' => $r['before']],
            $drifted
        ), JSON_PRETTY_PRINT));

        $written = 0;
        foreach ($drifted as $r) {
            $p = Property::withoutGlobalScopes()->find($r['id']);
            if (!$p) {
                continue;
            }
            $p->street_number = $r['after']['street_number'] ?: null;
            $p->street_name   = $r['after']['street_name'] ?: null;
            $p->complex_name  = $r['after']['complex_name'] ?: null;
            $p->unit_number   = $r['after']['unit_number'] ?: null;
            $p->address       = $r['after']['address'];
            $p->save();     // the observer re-derives address from the (now clean) parts
            $written++;
        }

        $this->line('');
        $this->info("Wrote {$written} properties. Snapshot: {$this->snapshotPath($path)}");
        $this->line("Reverse with: php artisan corex:reconcile-property-addresses --rollback={$path}");

        return self::SUCCESS;
    }

    /** The real on-disk path — Laravel's `local` disk roots at storage/app/private. */
    private function snapshotPath(string $relative): string
    {
        return Storage::path($relative);
    }

    private function renderRow(array $r): void
    {
        $this->line(sprintf('<fg=cyan>#%d</>  <fg=gray>[%s]</>  %s', $r['id'], $r['rule'], $r['reason']));
        $this->line(sprintf('    before  address : %s', json_encode($r['before']['address'])));
        $this->line(sprintf('            parts   : %s', $this->partsLine($r['before'])));
        $this->line(sprintf('    <fg=green>after   address : %s</>', json_encode($r['after']['address'])));
        $this->line(sprintf('            <fg=green>parts   : %s</>', $this->partsLine($r['after'])));
        $this->line('');
    }

    private function partsLine(array $p): string
    {
        return sprintf('unit=%s complex=%s street=%s %s',
            $p['unit_number'] !== '' ? $p['unit_number'] : '—',
            $p['complex_name'] !== '' ? '"' . $p['complex_name'] . '"' : '—',
            $p['street_number'] !== '' ? $p['street_number'] : '—',
            $p['street_name'] !== '' ? '"' . $p['street_name'] . '"' : '—',
        );
    }

    private function rollback(string $file): int
    {
        if (!Storage::exists($file)) {
            $this->error("Snapshot not found: {$this->snapshotPath($file)}");
            return self::FAILURE;
        }

        $rows = json_decode((string) Storage::get($file), true) ?: [];
        $restored = 0;

        foreach ($rows as $row) {
            $p = Property::withoutGlobalScopes()->find($row['id'] ?? 0);
            if (!$p) {
                continue;
            }
            $b = $row['before'];
            $p->street_number = $b['street_number'] ?: null;
            $p->street_name   = $b['street_name'] ?: null;
            $p->complex_name  = $b['complex_name'] ?: null;
            $p->unit_number   = $b['unit_number'] ?: null;
            $p->saveQuietly();                       // do NOT let the observer re-derive
            $p->address = $b['address'] ?: null;     // restore the ORIGINAL string verbatim
            $p->saveQuietly();
            $restored++;
        }

        $this->info("Restored {$restored} properties from {$file}.");

        return self::SUCCESS;
    }
}
