<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk-syndicate on-market (status=active) stock to Private Property,
 * SEQUENTIALLY — one listing at a time, waiting for each PP response before the
 * next (natural self-throttle). Only properties that pass field-readiness
 * (checkReadiness empty) are pushed; not-ready listings are skipped and reported.
 *
 * Mirrors p24:bulk-syndicate. Idempotent: submitListing() updates the existing
 * PP listing when a pp_ref is present, creates it otherwise. Re-running is safe;
 * failures land in pp_syndication_status='error' with pp_last_error, and
 * --retry-errors limits a re-run to just those.
 *
 * Results are written to storage/app/<results-file> for reporting.
 */
class BulkSyndicatePP extends Command
{
    protected $signature = 'pp:bulk-syndicate
        {--agency=1 : Agency ID to syndicate}
        {--dry-run : List the ready set and exit without pushing}
        {--limit=0 : Cap the number pushed (0 = no cap)}
        {--retry-errors : Only push listings currently in error status}
        {--sleep=3 : Seconds to pause between pushes (self-throttle)}
        {--results=pp-bulk-syndicate-results.json : Results file under storage/app}';

    protected $description = 'Sequentially syndicate ready on-market properties to Private Property';

    public function handle(PrivatePropertySyndicationService $svc, PrivatePropertyListingMapper $mapper): int
    {
        $agencyId = (int) $this->option('agency');
        $dryRun   = (bool) $this->option('dry-run');
        $limit    = (int) $this->option('limit');
        $sleep    = max(0, (int) $this->option('sleep'));

        $query = Property::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('status', 'active');

        if ($this->option('retry-errors')) {
            $query->where('pp_syndication_status', 'error');
        }

        $active = $query->get();

        // Field-readiness filter — never push a listing PP will reject.
        $ready = $active->filter(fn (Property $p) => empty($mapper->checkReadiness($p)))->values();
        if ($limit > 0) {
            $ready = $ready->take($limit)->values();
        }

        $this->info("Agency {$agencyId}: active={$active->count()}  ready={$ready->count()}  (sleep {$sleep}s between pushes)");

        if ($dryRun) {
            $this->line('DRY RUN — ready ids: ' . $ready->pluck('id')->implode(','));
            return self::SUCCESS;
        }

        $ok = 0; $fail = 0; $results = []; $i = 0;
        $total = $ready->count();

        foreach ($ready as $p) {
            $i++;
            // Flag the toggle without firing observers (raw write); submitListing
            // is the explicit push.
            DB::table('properties')->where('id', $p->id)->update(['pp_syndication_enabled' => true]);

            try {
                $res = $svc->submitListing($p);
                $success = (bool) ($res['success'] ?? false);
                if ($success) {
                    $ok++;
                } else {
                    $fail++;
                }
                $results[] = [
                    'id'      => $p->id,
                    'title'   => $p->title,
                    'success' => $success,
                    'message' => $res['message'] ?? '',
                    'pp_ref'  => $p->fresh()->pp_ref,
                ];
            } catch (\Throwable $e) {
                $fail++;
                $results[] = ['id' => $p->id, 'title' => $p->title, 'success' => false, 'message' => 'EXCEPTION: ' . $e->getMessage()];
            }

            if ($i % 25 === 0 || $i === $total) {
                $this->info("  progress {$i}/{$total} — ok={$ok} fail={$fail}");
            }

            if ($i < $total && $sleep > 0) {
                sleep($sleep);
            }
        }

        $payload = [
            'agency_id' => $agencyId,
            'attempted' => $total,
            'succeeded' => $ok,
            'failed'    => $fail,
            'results'   => $results,
        ];
        Storage::disk('local')->put($this->option('results'), json_encode($payload, JSON_PRETTY_PRINT));

        $this->info("DONE — attempted {$total}, succeeded {$ok}, failed {$fail}. Results: storage/app/" . $this->option('results'));

        if ($fail > 0) {
            $this->warn('Failures:');
            foreach (array_filter($results, fn ($r) => ! $r['success']) as $r) {
                $this->line("  #{$r['id']} {$r['title']} — {$r['message']}");
            }
        }

        return self::SUCCESS;
    }
}
