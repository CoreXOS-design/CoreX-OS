<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealLinkReviewQueue;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 3i F1+F2 — admin UI for resolving ambiguous deal→property matches.
 *
 * Access: admin / super_admin / branch_manager / principal (handled by
 * route middleware + an explicit assertAdmin guard).
 */
final class DealLinkReviewController extends Controller
{
    /** GET /corex/admin/deal-link-review */
    public function index(Request $request): View
    {
        $this->assertAdmin($request);

        $agencyId = (int) $request->user()?->effectiveAgencyId();
        $status   = $request->string('status')->toString() ?: DealLinkReviewQueue::STATUS_PENDING;

        $rows = DealLinkReviewQueue::where('agency_id', $agencyId)
            ->where('match_status', $status)
            ->with(['deal:id,agency_id,property_address,registration_date,property_value,sale_price'])
            ->orderByDesc('matched_at')
            ->paginate(40)
            ->withQueryString();

        $counts = [
            'pending'           => DealLinkReviewQueue::where('agency_id', $agencyId)
                ->where('match_status', DealLinkReviewQueue::STATUS_PENDING)->count(),
            'resolved_linked'   => DealLinkReviewQueue::where('agency_id', $agencyId)
                ->where('match_status', DealLinkReviewQueue::STATUS_RESOLVED_LINKED)->count(),
            'resolved_unlinked' => DealLinkReviewQueue::where('agency_id', $agencyId)
                ->where('match_status', DealLinkReviewQueue::STATUS_RESOLVED_UNLINKED)->count(),
            'resolved_skip'     => DealLinkReviewQueue::where('agency_id', $agencyId)
                ->where('match_status', DealLinkReviewQueue::STATUS_RESOLVED_SKIP)->count(),
        ];

        return view('admin.deal-link-review.index', [
            'rows'   => $rows,
            'counts' => $counts,
            'status' => $status,
        ]);
    }

    /** GET /corex/admin/deal-link-review/{id} */
    public function show(Request $request, DealLinkReviewQueue $reviewItem): View
    {
        $this->assertAdmin($request);
        $this->guardAgency($request, $reviewItem);

        $deal = $reviewItem->deal()->first();
        $candidates = collect($reviewItem->candidates_json ?? []);

        // Hydrate full property rows for each candidate so the side-by-side
        // render can show current status + listed_price + activity date.
        $candidatePropertyIds = $candidates->pluck('property_id')->all();
        $properties = Property::withoutGlobalScopes()
            ->whereIn('id', $candidatePropertyIds)
            ->get(['id', 'address', 'suburb', 'status', 'price', 'last_activity_at', 'beds', 'baths'])
            ->keyBy('id');

        return view('admin.deal-link-review.show', [
            'item'       => $reviewItem,
            'deal'       => $deal,
            'candidates' => $candidates,
            'properties' => $properties,
        ]);
    }

    /** POST /corex/admin/deal-link-review/{id}/link */
    public function link(Request $request, DealLinkReviewQueue $reviewItem): RedirectResponse
    {
        $this->assertAdmin($request);
        $this->guardAgency($request, $reviewItem);

        $data = $request->validate([
            'property_id'  => 'required|integer|exists:properties,id',
            'review_note'  => 'nullable|string|max:2000',
        ]);

        // Sanity — picked property must belong to same agency.
        $prop = Property::withoutGlobalScopes()->find($data['property_id']);
        if (!$prop || (int) $prop->agency_id !== (int) $reviewItem->agency_id) {
            return back()->withErrors(['property_id' => 'Property must belong to the same agency.']);
        }

        DB::transaction(function () use ($reviewItem, $request, $data) {
            $deal = $reviewItem->deal;
            if ($deal) {
                $deal->forceFill([
                    'property_id'              => (int) $data['property_id'],
                    'link_source'              => 'admin_review',
                    'link_confidence'          => 'exact',
                    'link_reviewed_at'         => now(),
                    'link_reviewed_by_user_id' => $request->user()->id,
                ])->save();
            }
            $reviewItem->forceFill([
                'match_status'        => DealLinkReviewQueue::STATUS_RESOLVED_LINKED,
                'chosen_property_id'  => (int) $data['property_id'],
                'reviewed_at'         => now(),
                'reviewed_by_user_id' => $request->user()->id,
                'review_note'         => $data['review_note'] ?? null,
            ])->save();
        });

        return redirect()->route('corex.admin.deal-link-review.index')
            ->with('status', 'Deal linked to property #' . $data['property_id'] . '.');
    }

    /** POST /corex/admin/deal-link-review/{id}/skip */
    public function skip(Request $request, DealLinkReviewQueue $reviewItem): RedirectResponse
    {
        return $this->resolveWithStatus($request, $reviewItem, DealLinkReviewQueue::STATUS_RESOLVED_SKIP, 'Deferred for later.');
    }

    /** POST /corex/admin/deal-link-review/{id}/unlink */
    public function unlink(Request $request, DealLinkReviewQueue $reviewItem): RedirectResponse
    {
        return $this->resolveWithStatus($request, $reviewItem, DealLinkReviewQueue::STATUS_RESOLVED_UNLINKED, 'Marked as no-match.');
    }

    private function resolveWithStatus(Request $request, DealLinkReviewQueue $reviewItem, string $status, string $message): RedirectResponse
    {
        $this->assertAdmin($request);
        $this->guardAgency($request, $reviewItem);

        $note = $request->input('review_note');

        $reviewItem->forceFill([
            'match_status'        => $status,
            'reviewed_at'         => now(),
            'reviewed_by_user_id' => $request->user()->id,
            'review_note'         => $note,
        ])->save();

        return redirect()->route('corex.admin.deal-link-review.index')
            ->with('status', $message);
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user) abort(403);
        $role = (string) $user->role;
        if (!in_array($role, ['super_admin', 'admin', 'branch_manager', 'principal'], true) && !$user->is_admin) {
            abort(403, 'Admin / branch manager only.');
        }
    }

    private function guardAgency(Request $request, DealLinkReviewQueue $reviewItem): void
    {
        $effective = (int) $request->user()?->effectiveAgencyId();
        if ((int) $reviewItem->agency_id !== $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
