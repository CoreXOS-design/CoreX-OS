<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ProspectingListing;
use App\Models\ProspectingListingDateCorrection;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 — correct a MIC listing's listed date, with a mandatory reason.
 *
 * Same pattern as PropertyListedDateController (permission + required reason + history), for the
 * Market Intelligence listing rather than a CoreX property. The portal's own date is stored by the
 * capture; this is the agent's override when the portal date is missing or wrong. An agent-set
 * date is never overwritten by a later capture (listed_date_source = 'agent').
 *
 * Scoping: the listing is looked up through the viewer's own Market Intelligence data scope
 * (agency via BelongsToAgency, then own/branch/all), so a direct URL to a listing outside it is a
 * 404, not a 403 — existence is not revealed.
 */
class ProspectingListingListedDateController extends Controller
{
    public function update(Request $request, int $listing)
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('mic.listed_date.correct'), 403);
        abort_unless($user->effectiveAgencyId(), 403);

        $row = ProspectingListing::query()
            ->visibleTo($user, PermissionService::marketIntelligenceScope($user))
            ->find($listing);
        abort_if($row === null, 404);

        $data = $request->validate([
            'listed_date' => 'required|date|before_or_equal:today|after_or_equal:2000-01-01',
            'reason'      => 'required|string|min:5|max:2000',
        ], [
            'reason.required' => 'Please give a reason for changing the listed date.',
            'reason.min'      => 'Please give a reason for changing the listed date.',
        ]);

        $old = $row->listed_date?->toDateString();
        $new = Carbon::parse($data['listed_date'])->toDateString();

        if ($old === $new && $row->listed_date_source === ProspectingListing::LISTED_DATE_SOURCE_AGENT) {
            return back()->with('error', 'The listed date is already ' . $new . '.');
        }

        DB::transaction(function () use ($row, $user, $old, $new, $data) {
            ProspectingListingDateCorrection::create([
                'agency_id'              => $row->agency_id,
                'prospecting_listing_id' => $row->id,
                'user_id'                => $user->id,
                'old_date'               => $old,
                'new_date'               => $new,
                'reason'                 => trim($data['reason']),
            ]);

            $row->forceFill([
                'listed_date'        => $new,
                'listed_date_source' => ProspectingListing::LISTED_DATE_SOURCE_AGENT,
            ])->save();
        });

        return back()->with('success', 'Listed date updated and the reason recorded.');
    }
}
