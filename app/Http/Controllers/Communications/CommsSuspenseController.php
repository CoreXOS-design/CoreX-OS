<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationFilingSuspense;
use App\Models\Deal;
use App\Services\Communications\CommunicationStorageService;
use App\Services\Communications\CorrespondenceFilingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * AT-231 P2b — the inbound attorney-correspondence REVIEW SCREEN (the suspense
 * queue). Parked emails that CoreX could not auto-file wait here for a human to
 * confirm-to-deal (verify + learn), reassign (correct a wrong deal), or reject
 * (dismiss). Reachable from BOTH the Deals nav and the Comms nav (spec §3.7).
 * The engine (park/verify/reassign/dismiss + learn) is CorrespondenceFilingService.
 */
class CommsSuspenseController extends Controller
{
    public function __construct(private CorrespondenceFilingService $filing)
    {
    }

    /** The review queue — pending parked correspondence the agent may act on. */
    public function index(Request $request): View
    {
        abort_unless(auth()->user()?->hasPermission('deal_comms_suspense.view'), 403);

        $user     = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?? 0);

        $items = CommunicationFilingSuspense::query()
            ->where('agency_id', $agencyId)
            ->where('status', CommunicationFilingSuspense::STATUS_PENDING)
            // Only items whose suggested deal is visible to this agent (or that have
            // no suggestion at all — LOW, awaiting a manual link).
            ->where(function ($q) use ($user) {
                $q->whereNull('suggested_deal_id')
                  ->orWhereHas('suggestedDeal', fn ($d) => $d->visibleTo($user));
            })
            ->with(['communication.attachments', 'suggestedDeal'])
            ->orderByDesc('id')
            ->paginate(20);

        // Recently filed — so the agent can REASSIGN a wrongly-linked email from the
        // same screen (spec §3.8), not just verify pending ones.
        $recent = CommunicationFilingSuspense::query()
            ->where('agency_id', $agencyId)
            ->where('status', CommunicationFilingSuspense::STATUS_VERIFIED)
            ->whereHas('resolvedDeal', fn ($d) => $d->visibleTo($user))
            ->with(['communication', 'resolvedDeal'])
            ->orderByDesc('resolved_at')
            ->limit(10)
            ->get();

        return view('corex.communications.comms-suspense', ['items' => $items, 'recent' => $recent]);
    }

    /** Confirm-to-deal: file the correspondence + learn the reference (first-verify). */
    public function verify(Request $request, CommunicationFilingSuspense $suspense)
    {
        $this->authorizeResolve($request, $suspense);

        $dealId = (int) $request->input('deal_id', (int) $suspense->suggested_deal_id);
        if ($dealId <= 0) {
            return back()->with('error', 'Pick a deal to file this correspondence.');
        }
        try {
            $this->filing->verify($suspense, $dealId, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Filed to the deal — future emails on this reference will file automatically.');
    }

    /** Reassign: move a wrongly-linked correspondence to the correct deal + correct the learned pattern. */
    public function reassign(Request $request, CommunicationFilingSuspense $suspense)
    {
        $this->authorizeResolve($request, $suspense);

        $dealId = (int) $request->input('deal_id', 0);
        if ($dealId <= 0) {
            return back()->with('error', 'Pick the correct deal.');
        }
        $comm = Communication::withoutGlobalScopes()->findOrFail($suspense->communication_id);
        try {
            $this->filing->reassign($comm, $dealId, $request->user(), $request->input('reason'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Re-filed to the corrected deal.');
    }

    /** Reject: not filable to any deal — dismiss (soft-withdraws provisional links). */
    public function dismiss(Request $request, CommunicationFilingSuspense $suspense)
    {
        $this->authorizeResolve($request, $suspense);
        $this->filing->dismiss($suspense, $request->user(), $request->input('reason'));

        return back()->with('status', 'Dismissed — not filed.');
    }

    /** JSON deal search for the "Link to deal…" / reassign picker (DR1 deals.id space). */
    public function dealSearch(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deal_comms_suspense.resolve'), 403);

        $user   = $request->user();
        $search = trim((string) $request->input('q', ''));
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }

        $deals = Deal::query()->visibleTo($user)
            ->where(function ($q) use ($search) {
                $q->where('property_address', 'like', "%{$search}%")
                  ->orWhere('deal_no', 'like', "%{$search}%")
                  ->orWhere('seller_name', 'like', "%{$search}%")
                  ->orWhere('buyer_name', 'like', "%{$search}%");
            })
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'deal_no', 'property_address', 'seller_name']);

        return response()->json($deals->map(fn ($d) => [
            'id'    => (int) $d->id,
            'label' => trim(($d->deal_no ? "#{$d->deal_no} · " : '') . ($d->property_address ?: '') . ($d->seller_name ? " · {$d->seller_name}" : '')),
        ])->all());
    }

    /** Serve a parked attachment inline — gated on the suspense view perm + agency. */
    public function attachment(Request $request, CommunicationAttachment $attachment)
    {
        abort_unless(auth()->user()?->hasPermission('deal_comms_suspense.view'), 403);
        abort_unless((int) $attachment->agency_id === (int) ($request->user()->effectiveAgencyId() ?? -1), 403);

        $bytes = app(CommunicationStorageService::class)->get((string) $attachment->storage_path);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type'        => $attachment->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . Str::of($attachment->filename ?: 'attachment')->replace('"', '') . '"',
        ]);
    }

    private function authorizeResolve(Request $request, CommunicationFilingSuspense $suspense): void
    {
        abort_unless(auth()->user()?->hasPermission('deal_comms_suspense.resolve'), 403);
        abort_unless((int) $suspense->agency_id === (int) ($request->user()->effectiveAgencyId() ?? -1), 403);
    }
}
