<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Presentation;
use App\Services\Presentations\CmaCoverageService;
use App\Services\Presentations\CompPoolBuilder;
use App\Support\Presentations\SubjectReportResolver;
use App\Support\Presentations\SuburbMatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY diagnostic — compares the Generate-modal market-anchor
 * ("Suggestion based on suburb data") computed two ways for a presentation:
 *
 *   NOW          — the current rule: in-window suburb comps only.
 *   WITH-EXEMPT  — the proposed same-subject exemption: also include the
 *                  subject's OWN report comps regardless of the date window
 *                  (price-band-waived), mirroring the hydrator + the coverage
 *                  badge (CmaCoverageService::countComps).
 *
 * Prints both pool counts and both suggested-price (anchor) rand figures plus
 * the delta, so the alignment decision is made on real numbers.
 *
 * Pure read: only SELECT queries, no inserts/updates, nothing persisted. The
 * anchor maths is CompPoolBuilder's and is identical for both runs — only the
 * candidate MEMBERSHIP differs.
 */
class PresentationsAnchorAuditCommand extends Command
{
    protected $signature = 'presentations:anchor-audit {id : Presentation id}';

    protected $description = 'Read-only: market-anchor NOW vs. same-subject exemption for a presentation (no writes).';

    public function handle(): int
    {
        $presId = (int) $this->argument('id');

        $p = Presentation::with('property')->find($presId);
        if (! $p) {
            $this->error("Presentation {$presId} not found on this connection (" . config('database.connections.' . config('database.default') . '.database') . ').');
            return self::FAILURE;
        }
        $prop = $p->property;
        if (! $prop) {
            $this->error("Presentation {$presId} has no linked property.");
            return self::FAILURE;
        }

        $agencyId = (int) $prop->agency_id;
        $suburb   = (string) ($prop->suburb ?? '');
        $propType = (string) ($prop->property_type ?? '');
        $lat      = $prop->latitude  !== null && $prop->latitude  !== '' ? (float) $prop->latitude  : null;
        $lng      = $prop->longitude !== null && $prop->longitude !== '' ? (float) $prop->longitude : null;
        $isDemo   = (bool) ($prop->is_demo ?? false);

        // Reuse the service's own agency thresholds for the period window.
        $svc = new CmaCoverageService();
        $ref = new \ReflectionMethod($svc, 'thresholdsForAgency');
        $ref->setAccessible(true);
        $period = (int) $ref->invoke($svc, $agencyId)['period_months'];

        $dateFrom = Carbon::today()->subMonths($period)->toDateString();
        $dateTo   = Carbon::today()->toDateString();

        $subjectReportIds = SubjectReportResolver::resolveReportIds(
            $agencyId,
            (string) ($prop->address ?? ''),
            $suburb,
        );

        $toCandidate = static fn ($r, bool $exempt) => [
            'key'           => 'r' . $r->id,
            'price'         => (int) $r->sale_price,
            'size_m2'       => $r->extent_m2 !== null ? (int) $r->extent_m2 : null,
            'property_type' => $r->property_type,
            'lat'           => $r->latitude,
            'lng'           => $r->longitude,
            'exempt'        => $exempt,
        ];

        // NOW — in-window comp rows, suburb-matched (current marketAnchor).
        $nowRows = DB::table('market_report_comp_rows')
            ->whereNull('deleted_at')->where('row_type', 'comp')
            ->whereNotNull('sale_date')->whereNotNull('sale_price')
            ->where('is_demo', $isDemo)
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->select(['id', 'sale_price', 'property_type', 'extent_m2', 'latitude', 'longitude', 'suburb_normalised', 'market_report_id'])
            ->get();

        $now = [];
        $inWindowIds = [];
        foreach ($nowRows as $r) {
            if (empty($r->suburb_normalised) || ! SuburbMatcher::matches($r->suburb_normalised, $suburb)) {
                continue;
            }
            $now[] = $toCandidate($r, false);
            $inWindowIds[(int) $r->id] = true;
        }

        // WITH-EXEMPT — NOW plus the subject's own report comps not already in
        // the window set, marked exempt (price-band-waived).
        $after = $now;
        $newEntrants = 0;
        if (! empty($subjectReportIds)) {
            $subjRows = DB::table('market_report_comp_rows')
                ->whereNull('deleted_at')->where('row_type', 'comp')
                ->whereNotNull('sale_price')
                ->where('is_demo', $isDemo)
                ->whereIn('market_report_id', $subjectReportIds)
                ->select(['id', 'sale_price', 'property_type', 'extent_m2', 'latitude', 'longitude', 'suburb_normalised', 'market_report_id'])
                ->get();
            foreach ($subjRows as $r) {
                if (isset($inWindowIds[(int) $r->id])) {
                    continue;
                }
                $after[] = $toCandidate($r, true);
                $newEntrants++;
            }
        }

        $config  = CompPoolBuilder::configForAgency(Agency::find($agencyId));
        $subject = ['title_type' => null, 'property_type' => $propType, 'lat' => $lat, 'lng' => $lng, 'erf_m2' => null];

        $builder  = new CompPoolBuilder();
        $resNow   = $builder->select($subject, $now, $config);
        $resAfter = $builder->select($subject, $after, $config);

        $anchorNow   = $resNow['anchor'];
        $anchorAfter = $resAfter['anchor'];
        $delta       = ($anchorNow !== null && $anchorAfter !== null) ? ($anchorAfter - $anchorNow) : null;
        $deltaPct    = ($anchorNow) ? round(($delta / $anchorNow) * 100, 2) : null;

        $fmt = static fn ($v) => $v === null ? '—' : 'R ' . number_format((int) $v, 0, '.', ' ');

        $this->line('');
        $this->line("===== marketAnchor audit — presentation {$presId} (property {$prop->id}) =====");
        $this->line('subject: ' . ($prop->address ?? '?') . " · suburb={$suburb} · type={$propType} · geo=" . ($lat !== null ? 'yes' : 'NO'));
        $this->line("period window: {$dateFrom} → {$dateTo} ({$period}m) · subject reports: [" . implode(',', $subjectReportIds) . ']');
        $this->line(str_repeat('-', 70));
        $this->line(sprintf('%-22s %12s %12s', '', 'NOW', 'WITH-EXEMPT'));
        $this->line(sprintf('%-22s %12d %12d', 'candidates fed',     $resNow['diagnostics']['n_candidates'] ?? 0, $resAfter['diagnostics']['n_candidates'] ?? 0));
        $this->line(sprintf('%-22s %12d %12d', 'after type-gate',    $resNow['diagnostics']['n_after_type'] ?? 0,  $resAfter['diagnostics']['n_after_type'] ?? 0));
        $this->line(sprintf('%-22s %12d %12d', 'after price-band',   $resNow['diagnostics']['n_after_price'] ?? 0, $resAfter['diagnostics']['n_after_price'] ?? 0));
        $this->line(sprintf('%-22s %12d %12d', 'final pool (count)', $resNow['diagnostics']['n_selected'] ?? 0,    $resAfter['diagnostics']['n_selected'] ?? 0));
        $this->line(sprintf('%-22s %12s %12s', 'anchor (suggested)', $fmt($anchorNow), $fmt($anchorAfter)));
        $this->line(str_repeat('-', 70));
        $this->line("new out-of-window subject comps available: {$newEntrants}");
        $this->line('PRICE MOVES BY: ' . ($delta === null ? '—' : $fmt($delta) . " ({$deltaPct}%)"));
        $this->line('(math note: both runs use the SAME CompPoolBuilder formulas; only the');
        $this->line(' candidate MEMBERSHIP differs — exempt comps waive the price band only,');
        $this->line(' still type-gated + radius-bound. Band/median/percentile are unchanged.)');
        $this->line('');

        return self::SUCCESS;
    }
}
