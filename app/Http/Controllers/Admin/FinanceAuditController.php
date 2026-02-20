<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\FinanceAuditRun;
use App\Models\FinanceAuditItem;
use Illuminate\Http\Request;

class FinanceAuditController extends Controller
{
    public function index(Request $request)
    {
        $runs = FinanceAuditRun::query()
            ->when($request->period, fn ($q, $p) => $q->where('period', $p))
            ->withCount([
                'items',
                'items as errors_count'   => fn ($q) => $q->where('severity', 'error'),
                'items as warnings_count' => fn ($q) => $q->where('severity', 'warn'),
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.finance.audit.index', compact('runs'));
    }

    public function run(Request $request, FinanceAuditRun $run)
    {
        $query = $run->items();

        if ($request->filled('severity') && $request->severity !== 'all') {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('definition_key')) {
            $query->where('definition_key', 'like', '%' . $request->definition_key . '%');
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->boolean('diff_only')) {
            $query->whereRaw('ABS(COALESCE(diff_numeric, 0)) > 0.01');
        }

        $query->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
              ->orderBy('id');

        $items = $query->paginate(50)->withQueryString();

        $counts = [
            'total'    => $run->items()->count(),
            'errors'   => $run->items()->where('severity', 'error')->count(),
            'warnings' => $run->items()->where('severity', 'warn')->count(),
        ];

        return view('admin.finance.audit.run', compact('run', 'items', 'counts'));
    }

    public function deal(Request $request, Deal $deal)
    {
        if ($request->filled('run_id')) {
            $run = FinanceAuditRun::findOrFail($request->run_id);
        } elseif ($request->filled('period')) {
            $run = FinanceAuditRun::where('period', $request->period)
                ->orderByDesc('id')
                ->firstOrFail();
        } else {
            $run = FinanceAuditRun::orderByDesc('id')->firstOrFail();
        }

        $items = FinanceAuditItem::where('audit_run_id', $run->id)
            ->where('entity_type', 'deal')
            ->where('entity_id', $deal->id)
            ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->orderBy('definition_key')
            ->get();

        return view('admin.finance.audit.deal', compact('run', 'deal', 'items'));
    }
}
