<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingStock;
use Illuminate\Http\Request;

class CompanyListingStockController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        abort_unless($u, 403);

        // Filters (same shape as Agent/BM)
        $statusFilter = (string)$request->get('status', 'active');
        $mandate = trim((string)$request->get('mandate', ''));
        $type = trim((string)$request->get('type', ''));

        // Dashboard click filters: active|dom|stale|expiring|expired|all
        $filter = strtolower(trim((string)$request->get('filter', '')));
        if (!in_array($filter, ['', 'active', 'dom', 'stale', 'expiring', 'expired', 'all'], true)) {
            $filter = '';
        }

        // MySQL day-diff expressions (day precision)
        $domExpr  = "DATEDIFF(CURDATE(), DATE(COALESCE(listed_at, created_at)))";
        $editExpr = "DATEDIFF(CURDATE(), DATE(COALESCE(modified_at, created_at)))";

        $q = ListingStock::query()
            ->where('source', 'propcon');

        // Status dropdown filter (same behaviour as Agent/BM)
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
              ->whereRaw("DATEDIFF(DATE(expires_at), CURDATE()) BETWEEN 0 AND 14");
        } elseif ($filter === 'expired') {
            $q->whereNotNull('expires_at')
              ->whereRaw("DATE(expires_at) < CURDATE()");
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
            ->selectRaw("AVG(DATEDIFF(CURDATE(), DATE(coalesce(listed_at, created_at)))) as v")
            ->value('v');
        $avgDom = $avgDom !== null ? (int)$avgDom : 0;

        $contextTitle = 'Company listings';
        $contextNote = 'Read-only view of company listing stock (Propcon).';
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

        return view('admin.listings.stock.index', [
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
