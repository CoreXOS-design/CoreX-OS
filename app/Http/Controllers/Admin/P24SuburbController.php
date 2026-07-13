<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P24Suburb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class P24SuburbController extends Controller
{
    private function ensureAccess(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_p24'), 403);
    }

    public function index(Request $request)
    {
        $this->ensureAccess();

        // p24_suburbs is a shared table: a handful of agency-curated mappings
        // live alongside the full ~27k-row national P24 location tree that
        // `php artisan p24:sync-locations` caches here. Rendering every row as
        // an editable form exhausted PHP memory, so filtering and paging are
        // done in SQL — only one page (100 rows) is ever materialised. Confirmed
        // mappings (the agency's verified areas) sort to the top.
        $search    = trim((string) $request->query('q', ''));
        $region    = trim((string) $request->query('region', ''));
        $confirmed = (string) $request->query('confirmed', '');

        $query = P24Suburb::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
                if (ctype_digit($search)) {
                    $q->orWhere('p24_id', (int) $search);
                }
            });
        }

        if ($region !== '') {
            $query->where('region', $region);
        }

        if ($confirmed === '1' || $confirmed === '0') {
            $query->where('confirmed', $confirmed === '1');
        }

        $suburbs = $query
            ->orderByDesc('confirmed')
            ->orderBy('name')
            ->paginate(100)
            ->withQueryString();

        // Distinct regions for the filter dropdown — a tiny, cheap projection
        // (not the full row set), so it stays within budget at 27k rows.
        $regions = P24Suburb::query()
            ->whereNotNull('region')
            ->where('region', '!=', '')
            ->distinct()
            ->orderBy('region')
            ->pluck('region');

        return view('admin.p24-suburbs', [
            'suburbs'         => $suburbs,
            'regions'         => $regions,
            'search'          => $search,
            'selectedRegion'  => $region,
            'selectedStatus'  => $confirmed,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureAccess();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'p24_id'          => 'nullable|integer|min:1',
            'region'          => 'nullable|string|max:100',
            'surrounding_ids' => 'nullable|string|max:500',
            'confirmed'       => 'nullable|boolean',
        ]);

        $slug = strtolower(str_replace(' ', '-', trim($validated['name'])));

        // Parse surrounding IDs from comma-separated string
        $surroundingIds = [];
        if (!empty($validated['surrounding_ids'])) {
            $surroundingIds = array_map('intval', array_filter(
                explode(',', $validated['surrounding_ids']),
                fn($v) => trim($v) !== ''
            ));
        }

        P24Suburb::create([
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'region'          => $validated['region'] ?? null,
            'surrounding_ids' => $surroundingIds,
            'confirmed'       => !empty($validated['confirmed']),
        ]);

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb added successfully.');
    }

    public function update(Request $request, P24Suburb $p24Suburb)
    {
        $this->ensureAccess();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'p24_id'          => 'nullable|integer|min:1',
            'region'          => 'nullable|string|max:100',
            'surrounding_ids' => 'nullable|string|max:500',
            'confirmed'       => 'nullable|boolean',
        ]);

        $slug = strtolower(str_replace(' ', '-', trim($validated['name'])));

        $surroundingIds = [];
        if (!empty($validated['surrounding_ids'])) {
            $surroundingIds = array_map('intval', array_filter(
                explode(',', $validated['surrounding_ids']),
                fn($v) => trim($v) !== ''
            ));
        }

        $p24Suburb->update([
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'region'          => $validated['region'] ?? null,
            'surrounding_ids' => $surroundingIds,
            'confirmed'       => !empty($validated['confirmed']),
        ]);

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb updated.');
    }

    public function destroy(P24Suburb $p24Suburb)
    {
        $this->ensureAccess();

        $p24Suburb->delete();

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb archived.');
    }
}
