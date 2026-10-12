<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\TitleDeeds\TitleDeedStorageLocation;
use App\Services\TitleDeeds\TitleDeedLocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/title-deed-register.md section 14.4 - Settings -> Title Deeds -> Storage locations. Permission
 * `title_deeds.manage_settings`, always the acting user's own agency, soft delete only. The Setup Wizard's list editor calls
 * `store()` and `destroy()` here, so the screen and the wizard share one write path.
 */
class TitleDeedLocationController extends Controller
{
    public function __construct(private TitleDeedLocationService $locations) {}

    public function index(Request $request)
    {
        $this->authorizeManage($request);
        $agencyId = (int) $request->user()->effectiveAgencyId();
        $search = trim((string) $request->query('q', ''));
        $showArchived = $request->boolean('archived');

        $query = TitleDeedStorageLocation::withoutGlobalScopes()->where('agency_id', $agencyId)->with('branch');
        $showArchived ? $query->onlyTrashed() : $query->whereNull('deleted_at');
        if ($search !== '') {
            $query->where('name', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%');
        }

        return view('title-deeds.locations', [
            'locations' => $query->ordered()->paginate(50)->withQueryString(),
            'branches' => Branch::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'showArchived' => $showArchived,
            'noAgency' => ! $agencyId,
            'activeCount' => TitleDeedStorageLocation::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->locations->create($request->user(), $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'branch_id' => ['nullable', 'integer'],
        ], ['name.required' => 'Give the location a name.']));

        return redirect()->route('corex.settings.title-deeds.locations')->with('success', 'Location added.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->locations->update($request->user(), $this->find($request, $id, true), $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'branch_id' => ['nullable', 'integer'],
        ], ['name.required' => 'Give the location a name.']));

        return redirect()->back()->with('success', 'Location saved.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->locations->archive($request->user(), $this->find($request, $id));

        return redirect()->back()->with('success', 'Location archived. Deeds kept there keep showing it.');
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->locations->restore($request->user(), $this->find($request, $id, true));

        return redirect()->back()->with('success', 'Location restored.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('title_deeds.manage_settings'), 403);
    }

    private function find(Request $request, int $id, bool $withTrashed = false): TitleDeedStorageLocation
    {
        $q = TitleDeedStorageLocation::withoutGlobalScopes()->where('agency_id', (int) $request->user()->effectiveAgencyId());
        if ($withTrashed) {
            $q->withTrashed();
        } else {
            $q->whereNull('deleted_at');
        }

        return $q->findOrFail($id);
    }
}
