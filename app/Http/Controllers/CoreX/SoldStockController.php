<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Property;
use App\Models\SoldStock;
use App\Models\User;
use App\Services\SoldStock\SoldStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Real Estate > Properties > Sold stock - the agency's frozen sales (read-only). Spec: .ai/specs/sold-stock.md sections 4-5.
 * Own / branch / agency scoping is applied in the query (SoldStock::scopeVisibleTo), never only in the UI.
 */
class SoldStockController extends Controller
{
    private const SORTS = [
        'sale_date' => 'sale_date', 'sale_price' => 'sale_price', 'suburb' => 'suburb',
        'days_on_market' => 'days_on_market', 'address' => 'address',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'sale_date';
        $dir  = $request->query('dir', $sort === 'sale_date' ? 'desc' : 'asc') === 'asc' ? 'asc' : 'desc';
        $archived = $request->boolean('archived') && $user->hasPermission('properties.archive');

        $query = SoldStock::query()->visibleTo($user)->with('agent:id,name');
        if ($archived) {
            $query->onlyTrashed();
        }

        if ($q = trim((string) $request->query('q'))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('address', 'like', $like)->orWhere('suburb', 'like', $like)->orWhere('title', 'like', $like)
                ->orWhereHas('agent', fn ($a) => $a->where('name', 'like', $like)));
        }

        [$from, $to] = $this->period($request);
        $from && $query->whereDate('sale_date', '>=', $from);
        $to && $query->whereDate('sale_date', '<=', $to);
        $request->filled('agent_id') && $query->where('agent_id', (int) $request->query('agent_id'));
        $request->filled('suburb') && $query->where('suburb', (string) $request->query('suburb'));
        $request->filled('branch_id') && $query->where('branch_id', (int) $request->query('branch_id'));

        $rows = $query->orderBy(self::SORTS[$sort], $dir)->orderByDesc('id')->paginate(25)->withQueryString();

        $scoped = SoldStock::query()->visibleTo($user);
        $agents = User::query()->whereIn('id', (clone $scoped)->whereNotNull('agent_id')->distinct()->pluck('agent_id'))->orderBy('name')->get(['id', 'name']);
        $suburbs = (clone $scoped)->whereNotNull('suburb')->distinct()->orderBy('suburb')->pluck('suburb');
        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);

        return view('corex.sold-stock.index', compact('rows', 'agents', 'suburbs', 'branches', 'sort', 'dir', 'archived') + [
            'filters' => $request->only(['q', 'period', 'from', 'to', 'agent_id', 'suburb', 'branch_id']),
            'showBranch' => $branches->count() > 1,
        ]);
    }

    public function show(Request $request, int $soldStock): View
    {
        $user = $request->user();
        $sale = SoldStock::query()->withTrashed()->visibleTo($user)->with(['agent:id,name', 'secondAgent:id,name', 'property'])->findOrFail($soldStock);
        $earlier = SoldStock::query()->visibleTo($user)->where('property_id', $sale->property_id)->where('id', '!=', $sale->id)->orderByDesc('cycle_no')->get();

        return view('corex.sold-stock.show', ['sale' => $sale, 'earlier' => $earlier]);
    }

    public function archive(Request $request, int $soldStock): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('properties.archive'), 403);
        SoldStock::query()->visibleTo($request->user())->findOrFail($soldStock)->delete();

        return redirect()->route('corex.sold-stock.index')->with('success', 'Sale archived. It no longer shows on the website Sold feed; restore it from the Archived view.');
    }

    public function restore(Request $request, int $soldStock): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('properties.archive'), 403);
        SoldStock::query()->onlyTrashed()->visibleTo($request->user())->findOrFail($soldStock)->restore();

        return redirect()->route('corex.sold-stock.index')->with('success', 'Sale restored.');
    }

    /** Agent confirms the previous buyer as the new seller of a re-prospected property. */
    public function confirmSuggestion(Request $request, int $property): RedirectResponse
    {
        $p = $this->editableProperty($request, $property);
        $ok = app(SoldStockService::class)->confirmSuggestion($p, $request->user()->id);

        return back()->with($ok ? 'success' : 'error', $ok ? 'Previous buyer confirmed as the new seller.' : 'There is no suggestion to confirm.');
    }

    public function dismissSuggestion(Request $request, int $property): RedirectResponse
    {
        $p = $this->editableProperty($request, $property);
        app(SoldStockService::class)->dismissSuggestion($p);

        return back()->with('success', 'Suggestion dismissed - add the new seller from the contacts panel.');
    }

    private function editableProperty(Request $request, int $id): Property
    {
        abort_unless($request->user()->hasPermission('properties.edit'), 403);

        return Property::query()->visibleTo($request->user())->findOrFail($id);
    }

    /** @return array{0:?string,1:?string} */
    private function period(Request $request): array
    {
        return match ((string) $request->query('period')) {
            '30'    => [now()->subDays(30)->toDateString(), null],
            '90'    => [now()->subDays(90)->toDateString(), null],
            'year'  => [now()->startOfYear()->toDateString(), null],
            default => [$request->filled('from') ? (string) $request->query('from') : null, $request->filled('to') ? (string) $request->query('to') : null],
        };
    }
}
