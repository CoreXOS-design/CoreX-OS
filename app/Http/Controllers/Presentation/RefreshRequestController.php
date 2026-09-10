<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\PresentationRefreshRequest;
use App\Services\Presentations\RefreshNotAllowedException;
use App\Services\Presentations\RefreshRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 7 — agent-side handling of refresh asks.
 *
 * Auth + agency-scoped. Same guard pattern as SnapshotLinkController:
 * effectiveAgencyId() must equal the request's agency_id.
 */
final class RefreshRequestController extends Controller
{
    public function __construct(private readonly RefreshRequestService $svc) {}

    /** GET /corex/presentations/refresh-requests */
    public function index(Request $request): View
    {
        $effective = $this->effectiveAgencyId($request);

        $status = $request->string('status')->toString() ?: 'open';

        $query = PresentationRefreshRequest::where('agency_id', $effective)
            ->with(['presentation:id,property_address,suburb', 'link:id,token,mode,recipient_label', 'recipientContact:id,first_name,last_name']);

        $query = match ($status) {
            'pending'      => $query->where('status', PresentationRefreshRequest::STATUS_PENDING),
            'acknowledged' => $query->where('status', PresentationRefreshRequest::STATUS_ACKNOWLEDGED),
            'resolved'     => $query->where('status', PresentationRefreshRequest::STATUS_RESOLVED),
            'declined'     => $query->where('status', PresentationRefreshRequest::STATUS_DECLINED),
            'all'          => $query,
            default        => $query->whereIn('status', [
                PresentationRefreshRequest::STATUS_PENDING,
                PresentationRefreshRequest::STATUS_ACKNOWLEDGED,
            ]),
        };

        // ->appends(), not left bare — page=2+ links must carry ?status= forward or
        // paging off page 1 of any non-default tab (Resolved, All, ...) silently
        // reverts to the "Open" default. See paginationQuery()'s doc for the related
        // ConvertEmptyStringsToNull gotcha this also guards against.
        $requests = $query->orderByDesc('created_at')->paginate(30)->appends($this->paginationQuery($request));

        $counts = [
            'pending'      => PresentationRefreshRequest::where('agency_id', $effective)
                ->where('status', PresentationRefreshRequest::STATUS_PENDING)->count(),
            'acknowledged' => PresentationRefreshRequest::where('agency_id', $effective)
                ->where('status', PresentationRefreshRequest::STATUS_ACKNOWLEDGED)->count(),
            'resolved'     => PresentationRefreshRequest::where('agency_id', $effective)
                ->where('status', PresentationRefreshRequest::STATUS_RESOLVED)->count(),
            'declined'     => PresentationRefreshRequest::where('agency_id', $effective)
                ->where('status', PresentationRefreshRequest::STATUS_DECLINED)->count(),
        ];
        $counts['open'] = $counts['pending'] + $counts['acknowledged'];

        return view('presentations.refresh-requests.index', [
            'requests' => $requests,
            'status'   => $status,
            'counts'   => $counts,
        ]);
    }

    /** POST /corex/presentations/refresh-requests/{request}/acknowledge */
    public function acknowledge(Request $request, PresentationRefreshRequest $refreshRequest): RedirectResponse
    {
        $this->guardAgency($request, $refreshRequest);
        $this->svc->acknowledge($refreshRequest, $request->user());
        return back()->with('status', 'Marked as acknowledged.');
    }

    /** POST /corex/presentations/refresh-requests/{request}/resolve */
    public function resolve(Request $request, PresentationRefreshRequest $refreshRequest): RedirectResponse
    {
        $this->guardAgency($request, $refreshRequest);

        $data = $request->validate([
            'keep_old_link_active' => 'sometimes|boolean',
            'resolution_note'      => 'nullable|string|max:2000',
            'version_id'           => 'nullable|integer|exists:presentation_versions,id',
        ]);

        try {
            $newLink = $this->svc->resolveWithRefresh($refreshRequest, $request->user(), [
                'keep_old_link_active' => (bool) ($data['keep_old_link_active'] ?? false),
                'resolution_note'      => $data['resolution_note'] ?? null,
                'version_id'           => isset($data['version_id']) ? (int) $data['version_id'] : null,
            ]);
        } catch (RefreshNotAllowedException $e) {
            return back()->withErrors(['resolve' => $e->getMessage()]);
        }

        return redirect()->route('presentations.show', $refreshRequest->presentation_id)
            ->with('status', 'New share link issued: ' . route('presentation.public.show', $newLink->token));
    }

    /** POST /corex/presentations/refresh-requests/{request}/decline */
    public function decline(Request $request, PresentationRefreshRequest $refreshRequest): RedirectResponse
    {
        $this->guardAgency($request, $refreshRequest);

        $data = $request->validate([
            'decline_reason'   => 'required|string|min:5|max:2000',
            'notify_requester' => 'sometimes|boolean',
        ]);

        try {
            $this->svc->decline(
                $refreshRequest,
                $request->user(),
                $data['decline_reason'],
                (bool) ($data['notify_requester'] ?? true),
            );
        } catch (RefreshNotAllowedException $e) {
            return back()->withErrors(['decline' => $e->getMessage()]);
        }

        return back()->with('status', 'Refresh request declined.');
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    private function effectiveAgencyId(Request $request): int
    {
        $effective = $request->user()?->effectiveAgencyId();
        if (!$effective) {
            abort(403, 'No effective agency.');
        }
        return (int) $effective;
    }

    private function guardAgency(Request $request, PresentationRefreshRequest $refreshRequest): void
    {
        $effective = $this->effectiveAgencyId($request);
        if ((int) $refreshRequest->agency_id !== $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
