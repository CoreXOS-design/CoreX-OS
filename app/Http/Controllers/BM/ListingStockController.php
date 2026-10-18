<?php

namespace App\Http\Controllers\BM;

use App\Http\Controllers\Controller;
use App\Models\ListingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ListingStockController extends Controller
{
    public function index(Request $request)
    {
        $u = Auth::user();
        abort_unless($u, 403);

        $branchId = (int)($u->effectiveBranchId() ?? ($u->branch_id ?? 0));
        if ($branchId === 0 && $u->isOwnerRole()) {
            $branchId = (int) \DB::table('branches')->orderBy('id')->value('id');
        }
        abort_unless($branchId > 0, 403);

        // Existing filters (keep same shape as agent listings)
        $statusFilter = (string)$request->get('status', 'active');
        $mandate = trim((string)$request->get('mandate', ''));
        $type = trim((string)$request->get('type', ''));

        // Dashboard click filters: active|dom|stale|expiring|expired|all
        $filter = strtolower(trim((string)$request->get('filter', '')));
        if (!in_array($filter, ['', 'active', 'dom', 'stale', 'expiring', 'expired', 'all'], true)) {
            $filter = '';
        }

        $isSqlite = \DB::connection()->getDriverName() === 'sqlite';
        $domExpr  = $isSqlite
            ? "CAST((julianday(DATE('now')) - julianday(DATE(COALESCE(listed_at, created_at)))) AS INTEGER)"
            : "DATEDIFF(CURDATE(), DATE(COALESCE(listed_at, created_at)))";
        $editExpr = $isSqlite
            ? "CAST((julianday(DATE('now')) - julianday(DATE(COALESCE(modified_at, created_at)))) AS INTEGER)"
            : "DATEDIFF(CURDATE(), DATE(COALESCE(modified_at, created_at)))";
        $expiresInExpr = $isSqlite
            ? "CAST((julianday(DATE(expires_at)) - julianday(DATE('now'))) AS INTEGER)"
            : "DATEDIFF(DATE(expires_at), CURDATE())";
        $todayExpr = $isSqlite ? "DATE('now')" : "CURDATE()";

        $q = ListingStock::query()
            ->where('source', 'propcon')
            // Branch scope: prefer listing_stocks.branch_id, fallback to user.branch_id via user_id
            ->where(function ($x) use ($branchId) {
                $x->where('branch_id', $branchId)
                  ->orWhereIn('user_id', function ($sq) use ($branchId) {
                      $sq->select('id')
                         ->from('users')
                         ->where('branch_id', $branchId)
                         ->where('is_active', 1);
                  });
            });

        // Breadth beyond the branch fence above: a role whose listings scope is 'own'
        // (or none) must not read the whole branch just because it holds the permission.
        $stockScope = \App\Services\PermissionService::getDataScope($u, 'listings');
        if ($stockScope === 'own') {
            $q->whereIn('user_id', $u->dataIdentityIds());
        } elseif (! in_array($stockScope, ['all', 'branch'], true)) {
            $q->whereRaw('1 = 0');
        }

        // Status dropdown filter (same behaviour as Agent)
        if ($statusFilter === 'active') {
            $q->where(function ($qq) {
                $qq->whereRaw("lower(coalesce(status,'')) like '%active%'")
                   ->orWhereRaw("lower(coalesce(status,'')) like '%for sale%'");
            });
        } elseif ($statusFilter !== 'all') {
            $needle = strtolower($statusFilter);
            $q->whereRaw("lower(coalesce(status,'')) like ?", ['%' . $needle . '%']);
        }

        // Apply dashboard-click filter constraints
        if ($filter === 'stale') {
            $q->whereRaw($editExpr . " >= 14");
        } elseif ($filter === 'expiring') {
            $q->whereNotNull('expires_at')
              ->whereRaw("$expiresInExpr BETWEEN 0 AND 14");
        } elseif ($filter === 'expired') {
            $q->whereNotNull('expires_at')
              ->whereRaw("DATE(expires_at) < $todayExpr");
        } elseif ($filter === 'all') {
            // no-op
        }

        // Text filters
        if ($mandate !== '') {
            $needle = strtolower($mandate);
            $q->whereRaw("lower(coalesce(mandate,'')) like ?", ['%' . $needle . '%']);
        }
        if ($type !== '') {
            $needle = strtolower($type);
            $q->whereRaw("lower(coalesce(type,'')) like ?", ['%' . $needle . '%']);
        }

        // Ordering
        $orderRaw = "coalesce(modified_at, listed_at, updated_at) desc";
        if ($filter === 'dom') {
            $orderRaw = $domExpr . " desc";
        } elseif ($filter === 'stale') {
            $orderRaw = $editExpr . " desc";
        } elseif ($filter === 'expiring') {
            $orderRaw = "date(expires_at) asc";
        } elseif ($filter === 'expired') {
            $orderRaw = "date(expires_at) desc";
        }

        $listings = (clone $q)
            ->orderByRaw($orderRaw)
            ->paginate(25)
            ->withQueryString();

        $summary = (clone $q)
            ->selectRaw("count(*) as listing_count, coalesce(sum(price_cents),0) as total_price_cents")
            ->first();

        $byMandate = (clone $q)
            ->selectRaw("coalesce(mandate,'(blank)') as label, count(*) as c")
            ->groupBy('label')
            ->orderByDesc('c')
            ->get();

        $byType = (clone $q)
            ->selectRaw("coalesce(type,'(blank)') as label, count(*) as c")
            ->groupBy('label')
            ->orderByDesc('c')
            ->get();

        $totalFiltered = (clone $q)->count();

        $avgDom = (clone $q)
            ->selectRaw("AVG($domExpr) as v")
            ->value('v');
        $avgDom = $avgDom !== null ? (int)$avgDom : 0;

        $contextTitle = 'Branch listings';
        $contextNote = 'Read-only view of branch listing stock (Propcon).';
        if ($filter === 'dom') {
            $contextTitle = 'DOM view';
            $contextNote = "Avg DOM {$avgDom} (compiled from DOM of every active listing).";
        } elseif ($filter === 'stale') {
            $contextTitle = 'Stale listings';
            $contextNote = "{$totalFiltered} listings (≥14 days since last edit).";
        } elseif ($filter === 'expiring') {
            $contextTitle = 'Expiring soon';
            $contextNote = "{$totalFiltered} listings (0–14 days to expiry).";
        } elseif ($filter === 'expired') {
            $contextTitle = 'Expired listings';
            $contextNote = "{$totalFiltered} listings (already past expiry).";
        } elseif ($filter === 'all' || $statusFilter === 'all') {
            $contextTitle = 'All listings';
            $contextNote = "{$totalFiltered} listings (no active-only filter).";
        } else {
            $contextTitle = 'Active listings';
            $contextNote = "{$totalFiltered} active listings (contains Active/For Sale).";
        }

        $context = [
            'title' => $contextTitle,
            'note' => $contextNote,
            'count' => $totalFiltered,
            'avg_dom' => $avgDom,
            'filter' => $filter,
            'status' => $statusFilter,
        ];

        return view('bm.listings.index', [
            'context' => $context,
            'listings' => $listings,
            'summary' => $summary,
            'byMandate' => $byMandate,
            'byType' => $byType,
            'statusFilter' => $statusFilter,
            'mandate' => $mandate,
            'type' => $type,
            'filter' => $filter,
        ]);
    }
}
