<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseNotice;
use App\Services\Rentals\LeaseNoticeReviewService;
use App\Services\Rentals\LeaseNoticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * .ai/specs/lease-notices.md - the agent's side of a notice given by the tenant or the landlord: the list, the review (the notice
 * beside the lease's terms and the flags) and the decision (accept / accept with a corrected date / reject).
 * (which tells the other party). A notice is exactly as visible as its lease (own / branch / agency); direct-URL access by id is blocked.
 */
class LeaseNoticeController extends Controller
{
    private const SORTS = ['notice_date', 'intended_end_date', 'status', 'property'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $query = LeaseNotice::query()->with(['lease.property', 'lease.tenants.contact', 'givenByContact'])
            ->whereIn('lease_id', Lease::query()->withTrashed()->visibleTo($user)->select('leases.id'));

        if ($request->boolean('archived')) {
            $query->onlyTrashed();
        }
        // default = what needs the agent: notices waiting for review
        $status = $request->has('status') ? (string) $request->get('status') : LeaseNotice::STATUS_RECEIVED;
        if ($status !== '' && in_array($status, LeaseNotice::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($request->get('given_by'), [Lease::NOTICE_BY_TENANT, Lease::NOTICE_BY_LANDLORD], true)) {
            $query->where('given_by', $request->get('given_by'));
        }
        if ($from = $request->get('date_from')) {
            $query->whereDate('notice_date', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('notice_date', '<=', $to);
        }
        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('lease.property', fn ($p) => $p->withTrashed()->where(fn ($pp) => $pp->where('title', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%")))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('givenByContact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $sort = in_array($request->get('sort'), self::SORTS, true) ? $request->get('sort') : 'notice_date';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';
        if ($sort === 'property') {
            $query->orderByRaw('(select p.address from properties p where p.id = (select l.property_id from leases l where l.id = lease_notices.lease_id)) ' . $dir);
        } else {
            $query->orderBy($sort, $dir);
        }
        $query->orderByDesc('id');

        return view('corex.lease-notices.index', [
            'notices' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function show(Request $request, int $id, LeaseNoticeReviewService $review): View
    {
        $notice = $this->find($request, $id);
        $lease = $notice->lease;
        $lease->loadMissing(['property', 'tenants.contact']);
        $flags = $notice->isReceived() || $notice->flags === null
            ? $review->flags($lease, $notice->given_by, $notice->notice_date, $notice->effectiveEndDate())
            : $notice->flags;

        return view('corex.lease-notices.show', [
            'notice' => $notice, 'lease' => $lease, 'terms' => $review->termsInForce($lease), 'flags' => $flags,
            'suggestedEnd' => $review->suggestedEndDate($flags), 'hasWarning' => $review->hasWarning($flags),
            'otherPartyNames' => $notice->otherParty() === Lease::NOTICE_BY_LANDLORD ? $lease->landlordNames() : $lease->tenantNames(),
        ]);
    }

    /** The agent records a notice on behalf of the tenant / landlord (the lease screen's dialogs). */
    public function store(Request $request, Lease $lease, LeaseNoticeService $service): RedirectResponse
    {
        $this->authoriseLease($lease, $request);
        $data = $request->validate([
            'given_by' => ['required', 'in:' . Lease::NOTICE_BY_TENANT . ',' . Lease::NOTICE_BY_LANDLORD],
            'notice_date' => ['required', 'date', 'before_or_equal:today'],
            'intended_end_date' => ['required', 'date', 'before_or_equal:' . now()->addYears(2)->toDateString()],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:' . LeaseNoticeService::ATTACHMENT_MAX_KB, 'mimes:' . LeaseNoticeService::ATTACHMENT_MIMES],
        ]);

        try {
            $notice = $service->give($lease, $data['given_by'], $data, LeaseNotice::VIA_AGENT, null, $request->user(), $request->file('attachment'));
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.lease-notices.show', $notice->id)->with('success', 'Notice recorded. Review it below - nothing has gone to the other party.');
    }

    public function update(Request $request, int $id, LeaseNoticeService $service): RedirectResponse
    {
        $notice = $this->find($request, $id);
        $data = $request->validate([
            'notice_date' => ['required', 'date', 'before_or_equal:today'],
            'intended_end_date' => ['required', 'date', 'before_or_equal:' . now()->addYears(2)->toDateString()],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $service->update($notice, $data, $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.lease-notices.show', $notice->id)->with('success', 'Notice details updated.');
    }

    public function accept(Request $request, int $id, LeaseNoticeService $service): RedirectResponse
    {
        $notice = $this->find($request, $id);
        $data = $request->validate([
            'end_date' => ['required', 'date', 'before_or_equal:' . now()->addYears(2)->toDateString()],
            'note' => ['nullable', 'string', 'max:1000'],
            'notice_outcome' => ['required', 'in:' . Lease::NOTICE_OUTCOME_READVERTISE . ',' . Lease::NOTICE_OUTCOME_WITHDRAW . ',' . Lease::NOTICE_OUTCOME_LEAVE],
            'show_available_from_on_portals' => ['nullable', 'boolean'],
        ], ['notice_outcome.required' => 'Choose what happens to the property.']);

        try {
            $service->accept($notice, $request->user(), [
                'end_date' => $data['end_date'], 'note' => $data['note'] ?? null, 'outcome' => $data['notice_outcome'],
                'show_available_from' => $request->has('show_available_from_on_portals') ? $request->boolean('show_available_from_on_portals') : null,
            ]);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.lease-notices.show', $notice->id)->with('success', 'Notice accepted - the move-out date is on the lease and the other party has been told.');
    }

    public function reject(Request $request, int $id, LeaseNoticeService $service): RedirectResponse
    {
        $notice = $this->find($request, $id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Say why the notice is not accepted - it is sent to the person who gave it.']);
        try {
            $service->reject($notice, $request->user(), $data['reason']);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.lease-notices.show', $notice->id)->with('success', 'Notice rejected. The person who gave it has been told why.');
    }

    public function attachment(Request $request, int $id)
    {
        $notice = $this->find($request, $id);
        abort_unless($notice->hasAttachment() && Storage::disk('local')->exists($notice->attachment_path), 404);

        return Storage::disk('local')->download($notice->attachment_path, $notice->attachment_name);
    }

    public function archive(Request $request, int $id, LeaseNoticeService $service): RedirectResponse
    {
        $service->archive($this->find($request, $id), $request->user());

        return redirect()->route('corex.lease-notices.index')->with('success', 'Notice archived.');
    }

    public function restore(Request $request, int $id, LeaseNoticeService $service): RedirectResponse
    {
        $notice = $this->find($request, $id);
        $service->restore($notice, $request->user());

        return redirect()->route('corex.lease-notices.show', $notice->id)->with('success', 'Notice restored.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** A notice, only if its lease is visible to this user (own / branch / agency). Anything else is a 403 / 404, never a peek. */
    private function find(Request $request, int $id): LeaseNotice
    {
        // an archived case must still open (to be restored); the list hides it unless the archived filter is on
        $query = LeaseNotice::query()->withTrashed();
        $notice = $query->findOrFail($id); // BelongsToAgency: another agency's id is a 404
        abort_unless(Lease::query()->withTrashed()->visibleTo($request->user())->whereKey($notice->lease_id)->exists(), 403);

        return $notice;
    }

    private function authoriseLease(Lease $lease, Request $request): void
    {
        abort_unless(Lease::query()->withTrashed()->visibleTo($request->user())->whereKey($lease->id)->exists(), 403);
    }
}
