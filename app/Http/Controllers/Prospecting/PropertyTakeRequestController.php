<?php

declare(strict_types=1);

namespace App\Http\Controllers\Prospecting;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Prospecting\PropertyTakeRequest;
use App\Services\Prospecting\PropertyDuplicateAgeResolver;
use App\Services\Prospecting\PropertyDuplicateAgeResult;
use App\Services\Prospecting\PropertyDuplicateTakeService;
use App\Services\Prospecting\PropertyTakeRequestNotifier;
use Illuminate\Http\Request;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21). BM/admin review of pending take requests — the
 * smallest thing that works: a pending state plus notify-and-confirm, shaped after
 * StaleClaimController (same permission gate, same list-then-decide pattern).
 */
class PropertyTakeRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        $requests = PropertyTakeRequest::where('agency_id', $agencyId)
            ->where('status', PropertyTakeRequest::STATUS_PENDING)
            ->with(['property', 'requestedBy'])
            ->orderBy('created_at')
            ->get();

        return view('corex.prospecting.property-take-requests', [
            'requests' => $requests,
        ]);
    }

    public function approve(
        Request $request,
        PropertyTakeRequest $propertyTakeRequest,
        PropertyDuplicateAgeResolver $ageResolver,
        PropertyDuplicateTakeService $takeService,
        PropertyTakeRequestNotifier $notifier,
    ) {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        abort_if((int) $propertyTakeRequest->agency_id !== $agencyId, 404);

        if (!$propertyTakeRequest->isPending()) {
            return back()->with('info', 'That request was already decided.');
        }

        $property = Property::withoutGlobalScopes()->findOrFail($propertyTakeRequest->property_id);
        $requester = $propertyTakeRequest->requestedBy;
        abort_if(!$requester, 404);

        // Re-resolve at DECISION time, not the request-time snapshot — an admin
        // reviewing days later must never reassign a property that has since gone
        // active (a live mandate could have been signed in the meantime).
        $age = $ageResolver->resolve($property);
        if ($age->band === PropertyDuplicateAgeResult::BAND_ACTIVE_BLOCKED) {
            return back()->with('error', 'This property is now live stock on the market — it cannot be approved.');
        }

        $takeService->reassign($property, $requester, $age);

        $propertyTakeRequest->update([
            'status' => PropertyTakeRequest::STATUS_APPROVED,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
        ]);

        $notifier->notifyRequesterOfDecision($propertyTakeRequest);

        return back()->with('status', 'Approved — taken by ' . $requester->name . '.');
    }

    public function reject(Request $request, PropertyTakeRequest $propertyTakeRequest, PropertyTakeRequestNotifier $notifier)
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        abort_if((int) $propertyTakeRequest->agency_id !== $agencyId, 404);

        if (!$propertyTakeRequest->isPending()) {
            return back()->with('info', 'That request was already decided.');
        }

        $data = $request->validate(['decision_note' => 'nullable|string|max:500']);

        $propertyTakeRequest->update([
            'status' => PropertyTakeRequest::STATUS_REJECTED,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
            'decision_note' => $data['decision_note'] ?? null,
        ]);

        $notifier->notifyRequesterOfDecision($propertyTakeRequest);

        return back()->with('status', 'Rejected.');
    }
}
