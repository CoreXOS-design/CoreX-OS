<?php

namespace App\Http\Controllers\TV;

use App\Models\Deal;
use App\Http\Controllers\Controller;
use App\Services\Admin\CompanyPerformanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchTvController extends Controller
{
    public function show(Request $request, CompanyPerformanceService $service, int $branchId)
    {
        $period = $request->query('period') ?: Carbon::now()->format('Y-m');

        // Basic branch existence check
        $branchName = DB::table('branches')->where('id', $branchId)->value('name');
        abort_unless($branchName, 404);

        // Single source of truth (same engine BM uses)
        $rollup = $service->getBranchRollup($branchId, $period);

                $statusSummary = Deal::statusSummaryForBranch((int)$branchId, (string)$period);
        // -----------------------------
        // Listing Stock Stats (TV)
        // -----------------------------
        $tvListings = \App\Models\ListingStock::query()
            ->where('source', 'propcon')
            ->where(function ($x) use ($branchId) {
                $x->where('branch_id', $branchId)
                  ->orWhereIn('user_id', function ($sq) use ($branchId) {
                      $sq->select('id')
                         ->from('users')
                         ->where('branch_id', $branchId)
                         ->where('is_active', 1);
                  });
            })
            ->where(function ($q) {
                $q->whereRaw("lower(coalesce(status,'')) like '%active%'")
                  ->orWhereRaw("lower(coalesce(status,'')) like '%for sale%'");
            })
            ->get();

        $tvTotal = $tvListings->count();

        $tvAvgDom = $tvTotal > 0
            ? (int) round($tvListings->filter(fn($l) => $l->days_on_market !== null)->avg('days_on_market') ?? 0)
            : 0;

        $tvStale = $tvListings->filter(fn($l) => $l->is_stale)->count();
        $tvExpiring = $tvListings->filter(fn($l) => $l->is_expiring_soon)->count();
        $tvExpired = $tvListings->filter(fn($l) => $l->is_expired)->count();

        $listingStats = [
            'total' => $tvTotal,
            'avg_days_on_market' => $tvAvgDom,
            'stale' => $tvStale,
            'expiring_soon' => $tvExpiring,
            'expired' => $tvExpired,
        ];




        // ---------------------------------
        // TV Messages (DB-driven + dynamic placeholders)
        // ---------------------------------
        $tvMessagesRaw = \App\Models\TvMessage::query()
            ->activeForBranch((int)$branchId)
            ->with(['creator:id,name,email'])
            ->get();

        // Build placeholder map from live rollup + listing stats
        $ph = [
            '{{branch_name}}'      => (string) $branchName,
            '{{period}}'           => (string) $period,

            '{{deals_target}}'     => (string) (int) ($r['totals']['targets']['deals'] ?? 0),
            '{{deals_actual}}'     => (string) (int) ($r['totals']['actuals']['deals'] ?? 0),
            '{{deals_remaining}}'  => (string) (int) max(((int)($r['totals']['targets']['deals'] ?? 0)) - ((int)($r['totals']['actuals']['deals'] ?? 0)), 0),

            '{{value_target}}'     => (string) number_format((float) ($r['totals']['targets']['value'] ?? 0), 0, '.', ''),
            '{{value_actual}}'     => (string) number_format((float) ($r['totals']['actuals']['value'] ?? 0), 0, '.', ''),
            '{{value_remaining}}'  => (string) number_format(max(((float)($r['totals']['targets']['value'] ?? 0)) - ((float)($r['totals']['actuals']['value'] ?? 0)), 0), 0, '.', ''),

            '{{points_target}}'    => (string) number_format((float) ($r['points']['target'] ?? 0), 0, '.', ''),
            '{{points_actual}}'    => (string) number_format((float) ($r['points']['actual'] ?? 0), 0, '.', ''),
            '{{points_status}}'    => (string) ($r['points']['status'] ?? '—'),

            '{{listings_active}}'  => (string) (int) ($listingStats['total'] ?? 0),
            '{{listings_avg_dom}}' => (string) (int) ($listingStats['avg_days_on_market'] ?? 0),
            '{{listings_stale}}'   => (string) (int) ($listingStats['stale'] ?? 0),
            '{{listings_expiring}}'=> (string) (int) ($listingStats['expiring_soon'] ?? 0),
            '{{listings_expired}}' => (string) (int) ($listingStats['expired'] ?? 0),
        ];

        // Render messages with placeholder substitution
        $tvMessages = $tvMessagesRaw->map(function ($m) use ($ph) {
            $msg = (string) ($m->message ?? '');
            if ($msg !== '') {
                $msg = strtr($msg, $ph);
            }
            return [
                'id' => $m->id,
                'branch_id' => $m->branch_id,
                'title' => $m->title,
                'message' => $msg,
                'display_area' => (string) ($m->display_area ?? 'both'),
                
                'is_enabled' => (bool) $m->is_enabled,
                'creator_name' => $m->creator->name ?? null,
            ];
        })->filter(fn($x) => trim((string)$x['message']) !== '')->values()->all();

return view('tv.branch', [
            'tvMessages' => $tvMessages,
            'tvMessagesRawCount' => is_countable($tvMessagesRaw) ? count($tvMessagesRaw) : 0,

            'listingStats' => $listingStats,

            'statusSummary' => $statusSummary,
            'rollup' => $rollup,
            'branchName' => $branchName,
        ]);
    }
}
