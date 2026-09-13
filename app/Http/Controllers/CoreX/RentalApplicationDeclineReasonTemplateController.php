<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalApplicationDeclineReasonTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Decline reason templates, 2026-09-15 — Johan's own design floor, stated
 * in the spec before this was written: full CRUD, soft delete + restore, a
 * list screen with search/sort/filter/pagination/empty state, OWN/BRANCH/
 * AGENCY scoping at the query layer. There is no "own"/"branch" narrower
 * than agency here — a decline reason template is agency-wide content by
 * its own nature (every authoriser in the agency picks from the same
 * list), so BelongsToAgency's global scope IS the floor and the ceiling;
 * see the spec's own "Scoping" note for why that's a deliberate reading of
 * the standard, not a shortcut around it.
 *
 * Boundary agreed with cc5 before either lane wrote code: this controller
 * is the CRUD/settings half only. The decline modal's picker, the send
 * step, and the email merge are cc5's own files — never touched here.
 */
class RentalApplicationDeclineReasonTemplateController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();

        $status = $request->query('status', 'active');
        $status = in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';

        $sort = $request->query('sort', 'sort_order');
        $sort = in_array($sort, ['sort_order', 'reason', 'created_at'], true) ? $sort : 'sort_order';
        $direction = $request->query('direction', 'asc');
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $query = RentalApplicationDeclineReasonTemplate::query()->where('agency_id', $agencyId);

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('reason', 'like', "%{$q}%")
                    ->orWhere('guidance', 'like', "%{$q}%");
            });
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'reason') {
            $query->orderBy('reason'); // stable tie-break, never a re-shuffling page 2
        }

        $templates = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('corex.rental-applications.decline-reason-templates.index', compact('templates', 'status', 'sort', 'direction', 'q'));
    }

    public function store(Request $request): RedirectResponse
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'guidance' => ['required', 'string', 'max:5000'],
        ]);

        RentalApplicationDeclineReasonTemplate::create([
            'agency_id' => $agencyId,
            'reason' => $validated['reason'],
            'guidance' => $validated['guidance'],
            'sort_order' => $this->nextSortOrder($agencyId),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Decline reason template added.');
    }

    public function update(Request $request, RentalApplicationDeclineReasonTemplate $declineReasonTemplate): RedirectResponse
    {
        $this->guardOwnAgency($request, $declineReasonTemplate);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'guidance' => ['required', 'string', 'max:5000'],
        ]);

        $declineReasonTemplate->update($validated);

        return back()->with('success', 'Decline reason template updated.');
    }

    public function archive(Request $request, RentalApplicationDeclineReasonTemplate $declineReasonTemplate): RedirectResponse
    {
        $this->guardOwnAgency($request, $declineReasonTemplate);

        $declineReasonTemplate->delete();

        return back()->with('success', 'Decline reason template archived.');
    }

    public function restore(Request $request, int $declineReasonTemplate): RedirectResponse
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        $template = RentalApplicationDeclineReasonTemplate::withTrashed()->where('agency_id', $agencyId)->findOrFail($declineReasonTemplate);

        $template->restore();

        return back()->with('success', 'Decline reason template restored.');
    }

    /**
     * Route-model binding resolves a template by id alone (no implicit
     * agency scope on a single-record binding) — this is the per-record
     * open, the same defence-in-depth pattern AuthorizesRentalApplication
     * Access::guardRentalApplication() documents for exactly this reason:
     * a record already excluded from the list by agency must not become
     * reachable again through a direct URL with someone else's id.
     */
    private function guardOwnAgency(Request $request, RentalApplicationDeclineReasonTemplate $template): void
    {
        abort_unless((int) $template->agency_id === (int) $request->user()->effectiveAgencyId(), 404);
    }

    private function nextSortOrder(int $agencyId): int
    {
        return (int) (RentalApplicationDeclineReasonTemplate::withTrashed()->where('agency_id', $agencyId)->max('sort_order') ?? -1) + 1;
    }
}
