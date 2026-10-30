<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RevenueBillingRecord;
use App\Models\RevenueProspect;
use App\Services\Revenue\RevenueCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** System owner > Revenue: signed agencies, the prospect pipeline and the 12-month cash flow. Owner only (route middleware). */
class RevenueController extends Controller
{
    public function __construct(private RevenueCalculator $calc) {}

    private const SIGNED_SORTS = ['name' => 'name', 'users' => 'users', 'price' => 'price', 'monthly' => 'monthly', 'start' => 'start', 'term' => 'term'];
    private const PIPE_SORTS = ['name', 'stage', 'users', 'price', 'chance', 'start', 'weighted'];

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['signed', 'pipeline', 'cashflow'], true) ? $request->query('tab') : 'signed';
        $q = trim((string) $request->query('q', ''));
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $sort = (string) $request->query('sort', 'name');
        $archived = $request->boolean('archived');
        $data = ['tab' => $tab, 'q' => $q, 'sort' => $sort, 'dir' => $dir, 'archived' => $archived, 'edit' => (int) $request->query('edit', 0)];

        if ($tab === 'signed') {
            $rows = $archived ? $this->archivedSigned() : $this->calc->signed();
            if ($q !== '') {
                $rows = $rows->filter(fn ($r) => stripos($r['name'] . ' ' . ($r['agency'] ?? ''), $q) !== false);
            }
            $key = array_key_exists($sort, self::SIGNED_SORTS) ? $sort : 'name';
            $rows = $rows->sortBy(fn ($r) => is_string($r[$key] ?? null) ? strtolower($r[$key]) : ($key === 'start' ? ($r['start']?->timestamp ?? 0) : ($r[$key] ?? 0)), SORT_REGULAR, $dir === 'desc')->values();
            $data += ['rows' => $rows, 'totals' => $this->calc->signedTotals($rows), 'agencies' => DB::table('agencies')->orderBy('name')->pluck('name', 'id')];
        } elseif ($tab === 'pipeline') {
            $rows = $archived
                ? RevenueProspect::onlyTrashed()->get()->map(fn ($p) => ['prospect' => $p] + $this->calc->prospectMoney($p))
                : $this->calc->pipeline();
            if ($q !== '') {
                $rows = $rows->filter(fn ($r) => stripos($r['prospect']->name . ' ' . $r['prospect']->contact . ' ' . $r['prospect']->notes, $q) !== false);
            }
            $key = in_array($sort, self::PIPE_SORTS, true) ? $sort : 'name';
            $val = fn ($r) => match ($key) {
                'name' => strtolower($r['prospect']->name), 'stage' => array_search($r['prospect']->stage, array_keys(RevenueProspect::STAGES)),
                'users' => $r['prospect']->users_max ?: ($r['prospect']->users_min ?: 0), 'price' => $r['low'] ?? 0, 'chance' => $r['prospect']->chance_percent,
                'start' => $r['prospect']->expected_start_month?->timestamp ?? 0, 'weighted' => $r['weighted'],
            };
            $rows = $rows->sortBy($val, SORT_REGULAR, $dir === 'desc')->values();
            $data += ['rows' => $rows, 'totals' => $this->calc->pipelineTotals($rows), 'agencies' => DB::table('agencies')->orderBy('name')->pluck('name', 'id')];
        } else {
            $flow = $this->calc->cashFlow();
            $data += ['flow' => $flow, 'max' => max(1, max(array_column($flow, 'total')))];
        }

        return view('admin.revenue.index', $data);
    }

    private function archivedSigned()
    {
        return RevenueBillingRecord::onlyTrashed()->get()->map(fn ($r) => $this->calc->signedRow($r));
    }

    // ── signed (billing records) ─────────────────────────────────────────

    public function saveSigned(Request $request, ?RevenueBillingRecord $record = null)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150', 'agency_id' => 'nullable|integer|exists:agencies,id', 'users' => 'nullable|integer|min:0|max:100000',
            'branches' => 'nullable|integer|min:1|max:500', 'override_monthly' => 'nullable|numeric|min:0|max:100000000', 'override_note' => 'nullable|string|max:150',
            'start_date' => 'nullable|date', 'term_months' => 'nullable|integer|min:0|max:600', 'notes' => 'nullable|string|max:2000',
        ]);
        $data['branches'] = $data['branches'] ?? 1;
        $record ? $record->update($data) : RevenueBillingRecord::create($data);

        return redirect()->route('admin.revenue.index', ['tab' => 'signed'])->with('success', 'Saved.');
    }

    public function archiveSigned(RevenueBillingRecord $record)
    {
        $record->delete();

        return redirect()->route('admin.revenue.index', ['tab' => 'signed'])->with('success', $record->name . ' archived.');
    }

    public function restoreSigned(int $id)
    {
        RevenueBillingRecord::onlyTrashed()->findOrFail($id)->restore();

        return redirect()->route('admin.revenue.index', ['tab' => 'signed'])->with('success', 'Restored.');
    }

    // ── pipeline ─────────────────────────────────────────────────────────

    public function saveProspect(Request $request, ?RevenueProspect $prospect = null)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150', 'contact' => 'nullable|string|max:150', 'stage' => 'required|in:' . implode(',', array_keys(RevenueProspect::STAGES)),
            'users_min' => 'nullable|integer|min:0|max:100000', 'users_max' => 'nullable|integer|min:0|max:100000|gte:users_min',
            'monthly_override' => 'nullable|numeric|min:0|max:100000000', 'chance_percent' => 'required|integer|min:0|max:100',
            'expected_start_month' => 'nullable|date_format:Y-m', 'notes' => 'nullable|string|max:2000',
        ]);
        $data['expected_start_month'] = ! empty($data['expected_start_month']) ? $data['expected_start_month'] . '-01' : null;
        $prospect ? $prospect->update($data) : RevenueProspect::create($data);

        return redirect()->route('admin.revenue.index', ['tab' => 'pipeline'])->with('success', 'Saved.');
    }

    public function archiveProspect(RevenueProspect $prospect)
    {
        $prospect->delete();

        return redirect()->route('admin.revenue.index', ['tab' => 'pipeline'])->with('success', $prospect->name . ' archived.');
    }

    public function restoreProspect(int $id)
    {
        RevenueProspect::onlyTrashed()->findOrFail($id)->restore();

        return redirect()->route('admin.revenue.index', ['tab' => 'pipeline'])->with('success', 'Restored.');
    }

    /** "Convert to signed": the prospect becomes a billing record (linked to the agency once it exists) and leaves the pipeline. */
    public function convert(Request $request, RevenueProspect $prospect)
    {
        $data = $request->validate(['agency_id' => 'nullable|integer|exists:agencies,id']);
        $record = RevenueBillingRecord::create([
            'agency_id' => $data['agency_id'] ?? null, 'name' => $data['agency_id'] ? (string) DB::table('agencies')->where('id', $data['agency_id'])->value('name') : $prospect->name,
            'users' => $prospect->users_max ?: $prospect->users_min, 'override_monthly' => $prospect->monthly_override, 'override_note' => $prospect->monthly_override ? 'Special price from the pipeline' : null,
            'start_date' => $prospect->expected_start_month, 'notes' => trim('From the pipeline. ' . (string) $prospect->notes),
        ]);
        $prospect->update(['stage' => 'signed', 'agency_id' => $data['agency_id'] ?? null, 'billing_record_id' => $record->id]);

        return redirect()->route('admin.revenue.index', ['tab' => 'signed'])->with('success', $record->name . ' is now a signed agency.');
    }
}
