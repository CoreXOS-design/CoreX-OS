<?php

declare(strict_types=1);

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * AT-520 (1 Nov, 76 Colin Street): put adverts back on the market that the Gone rule (or the old suburb reconcile) set off-market while the portal still lists them. The caller
 * gives the portal refs that were SEEN live (one per line, optional second column = the time they were seen) - this never decides that itself. Only adverts currently
 * gone / probably sold / withdrawn are touched; sold never. Each one becomes active (probably sold -> under offer again), off_market_at cleared, last_seen_at set to the
 * time they were seen live, miss count cleared. Journalled one line per advert (old and new values); `--undo=<journal>` puts every field back. Dry run unless --apply.
 */
final class RestoreWronglyGone extends Command
{
    protected $signature = 'mic:restore-gone {--agency= : Agency id} {--refs-file= : File of portal refs seen live (ref[TAB]seen-at)} {--apply : Really restore} {--undo= : A journal to put back} {--report-dir= : Where the journal goes}';

    protected $description = 'Restore adverts wrongly set gone / probably sold / withdrawn (journalled, reversible)';

    private const FIELDS = ['is_active', 'portal_status', 'portal_status_changed_at', 'off_market_at', 'last_seen_at', 'missed_crawls', 'missed_crawl_id'];

    public function handle(): int
    {
        if ($this->option('undo')) {
            $n = 0;
            foreach (array_filter(array_map('trim', file((string) $this->option('undo')) ?: [])) as $line) {
                $e = json_decode($line, true);
                if (is_array($e) && isset($e['id'], $e['old'])) {
                    $n += DB::table('prospecting_listings')->where('id', $e['id'])->update($e['old']);
                }
            }
            $this->info("Put back {$n} adverts.");

            return self::SUCCESS;
        }
        $agencyId = (int) $this->option('agency');
        $file = (string) $this->option('refs-file');
        if ($agencyId <= 0 || $file === '' || ! is_file($file)) {
            $this->error('Give --agency=<id> and --refs-file=<file>.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $seen = [];
        foreach (array_filter(array_map('trim', file($file) ?: [])) as $line) {
            [$ref, $at] = array_pad(explode("\t", $line), 2, null);
            $seen[$ref] = $at ?: now()->toDateTimeString();
        }
        $dir = (string) ($this->option('report-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $journal = $apply ? fopen($dir . "/restore-gone-agency{$agencyId}-journal-" . now()->format('Ymd-His') . '.jsonl', 'ab') : null;

        $by = ['gone' => 0, 'probably_sold' => 0, 'withdrawn' => 0];
        $done = 0;
        DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereIn('portal_ref', array_keys($seen))->whereNull('deleted_at')
            ->whereIn('portal_status', ['gone', 'probably_sold', 'withdrawn'])->orderBy('id')
            ->get(array_merge(['id', 'portal_ref'], self::FIELDS))->each(function ($r) use ($apply, $seen, $journal, &$by, &$done) {
                $by[$r->portal_status]++;
                $done++;
                if (! $apply) {
                    return;
                }
                $old = [];
                foreach (self::FIELDS as $f) {
                    $old[$f] = $r->{$f};
                }
                $new = ['is_active' => 1, 'portal_status' => $r->portal_status === 'probably_sold' ? ProspectingListing::PORTAL_STATUS_UNDER_OFFER : ProspectingListing::PORTAL_STATUS_ACTIVE,
                    'portal_status_changed_at' => now(), 'off_market_at' => null, 'last_seen_at' => $seen[$r->portal_ref], 'missed_crawls' => 0, 'missed_crawl_id' => null];
                fwrite($journal, json_encode(['id' => (int) $r->id, 'ref' => $r->portal_ref, 'old' => $old, 'new' => $new], JSON_UNESCAPED_SLASHES) . "\n");
                DB::table('prospecting_listings')->where('id', $r->id)->update($new);
            });
        $journal && fclose($journal);
        $this->line(($apply ? 'RESTORED ' : 'DRY RUN: would restore ') . "{$done} adverts of " . count($seen) . ' seen live; was ' . json_encode($by));

        return self::SUCCESS;
    }
}
