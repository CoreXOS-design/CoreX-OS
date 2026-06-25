<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-syndicate on-market (status=active) stock to Property24, SEQUENTIALLY —
 * one listing at a time, waiting for each P24 response before the next (natural
 * self-throttle). Only properties that pass field-readiness (checkReadiness empty)
 * are pushed; not-ready listings are skipped and reported. Compliance is NOT an
 * additional gate here — the dev override stands (Johan's call, AT-P24).
 *
 * Idempotent: every target already has a p24_ref (imported from P24), so
 * submitListing() updates the existing listing. Re-running is safe; failures land
 * in p24_syndication_status='error' with p24_last_error, and --retry-errors limits
 * a re-run to just those.
 *
 * Results are written to storage/app/<results-file> for reporting.
 */
class BulkSyndicateP24 extends Command
{
    protected $signature = 'p24:bulk-syndicate
        {--agency=1 : Agency ID to syndicate}
        {--dry-run : List the ready set and exit without pushing}
        {--limit=0 : Cap the number pushed (0 = no cap)}
        {--retry-errors : Only push listings currently in error status}
        {--sleep=3 : Seconds to pause between pushes (self-throttle)}
        {--results=p24-bulk-syndicate-results.json : Results file under storage/app}';

    protected $description = 'Sequentially syndicate ready on-market properties to Property24';

    public function handle(Property24SyndicationService $svc, Property24ListingMapper $mapper): int
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
            $query->where('p24_syndication_status', 'error');
        }

        $active = $query->get();

        // Field-readiness filter — never push a listing P24 will reject.
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
            DB::table('properties')->where('id', $p->id)->update(['p24_syndication_enabled' => true]);

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
                    'p24_ref' => $p->fresh()->p24_ref,
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
        \Illuminate\Support\Facades\Storage::disk('local')->put($this->option('results'), json_encode($payload, JSON_PRETTY_PRINT));

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
