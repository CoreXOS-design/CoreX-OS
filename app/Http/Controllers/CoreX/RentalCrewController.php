<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalCrew;
use App\Models\RentalCrewMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 2026-10-05 — Johan's ruling: "Agents and staff are never maintenance
 * crew. Crew are people with NO CoreX access, set up by the agency admin,
 * pickable on job cards." Full CRUD + list-screen floor (BUILD_STANDARD
 * §1a-§1d) — mirrors RentalCatalogueItemController's own shape. A crew's
 * members are managed inline on its own edit screen, same "parent record
 * owns its children" pattern RentalJobCard's own tasks/lines already use.
 * Permission: same key as managing the catalogue (Johan's own instruction)
 * — no new permission key introduced.
 */
class RentalCrewController extends Controller
{
    public function index(Request $request): View
    {
        $query = RentalCrew::query()->withCount('members');

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $status = $request->get('status', 'active');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } else {
            $query->where('is_active', true);
        }

        $sort = $request->get('sort', 'name');
        $allowedSorts = ['name', 'created_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'name';
        }
        $direction = $request->get('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        $hasAny = RentalCrew::query()->exists();
        $crews = $query->paginate(25)->withQueryString();

        return view('corex.rental-crews.index', compact('crews', 'status', 'sort', 'direction', 'hasAny'));
    }

    public function create(): View
    {
        return view('corex.rental-crews.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $crew = RentalCrew::create($data + ['created_by_user_id' => $request->user()->id]);

        return redirect()->route('corex.rental-crews.edit', $crew)->with('success', "Crew '{$crew->name}' added.");
    }

    public function edit(RentalCrew $rentalCrew): View
    {
        $rentalCrew->load(['members' => fn ($q) => $q->orderBy('name')]);
        $archivedMembers = $rentalCrew->members()->onlyTrashed()->get();

        return view('corex.rental-crews.edit', ['crew' => $rentalCrew, 'archivedMembers' => $archivedMembers]);
    }

    public function update(Request $request, RentalCrew $rentalCrew): RedirectResponse
    {
        $rentalCrew->update($this->validated($request, $rentalCrew));

        return redirect()->route('corex.rental-crews.edit', $rentalCrew)->with('success', "Crew '{$rentalCrew->name}' updated.");
    }

    public function archive(RentalCrew $rentalCrew): RedirectResponse
    {
        $rentalCrew->archive();

        return redirect()->route('corex.rental-crews.index')->with('success', "Crew '{$rentalCrew->name}' archived.");
    }

    public function restore(int $rentalCrew): RedirectResponse
    {
        $crew = RentalCrew::onlyTrashed()->findOrFail($rentalCrew);
        $crew->restoreRecord();

        return redirect()->route('corex.rental-crews.edit', $crew)->with('success', "Crew '{$crew->name}' restored.");
    }

    // ── Members — managed inline on the crew's own edit screen ───────────

    public function storeMember(Request $request, RentalCrew $rentalCrew): RedirectResponse
    {
        $data = $this->validatedMember($request);

        $rentalCrew->members()->create($data + [
            'agency_id' => $rentalCrew->agency_id,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Member added.');
    }

    public function updateMember(Request $request, RentalCrew $rentalCrew, RentalCrewMember $member): RedirectResponse
    {
        abort_unless($member->rental_crew_id === $rentalCrew->id, 404);

        $member->update($this->validatedMember($request));

        return back()->with('success', 'Member updated.');
    }

    public function archiveMember(RentalCrew $rentalCrew, RentalCrewMember $member): RedirectResponse
    {
        abort_unless($member->rental_crew_id === $rentalCrew->id, 404);

        $member->archive();

        return back()->with('success', 'Member archived.');
    }

    public function restoreMember(RentalCrew $rentalCrew, int $member): RedirectResponse
    {
        $m = RentalCrewMember::onlyTrashed()->where('rental_crew_id', $rentalCrew->id)->findOrFail($member);
        $m->restoreRecord();

        return back()->with('success', 'Member restored.');
    }

    /**
     * Unique per agency AMONG ACTIVE crews only (Johan) — application-layer,
     * not a DB constraint; see the migration's own docblock for why. An
     * archived crew's name is free to be reused by a brand-new one.
     */
    private function validated(Request $request, ?RentalCrew $editing = null): array
    {
        $agencyId = $request->user()->agency_id;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('rental_crews', 'name')
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->ignore($editing?->id),
            ],
            // §14.27.6 item 1 — where a job card's crew link is sent. Both optional:
            // a crew with no address can still be sent a link to any address typed.
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+()\-. ]{5,30}$/'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'phone.regex' => 'The contact number may only contain digits, spaces and + ( ) - . and must be 5 to 30 characters.',
        ]);
        $data['email'] = isset($data['email']) && trim($data['email']) !== '' ? trim($data['email']) : null;
        $data['phone'] = isset($data['phone']) && trim($data['phone']) !== '' ? trim($data['phone']) : null;
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function validatedMember(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
