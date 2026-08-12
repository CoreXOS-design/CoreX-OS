<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CMA / deeds capture (phase 1) — the dedicated "Deeds Capture" screen. Lists
 * un-promoted deeds captures (property + owner + owner ID) and promotes one into
 * a real Property + owner Contact link. Deliberately SEPARATE from MIC
 * Opportunities (Johan's directive) — same tracked_properties plumbing, own screen.
 */
final class DeedsCaptureController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if($agencyId === null, 403, 'No agency context.');

        $captures = TrackedProperty::query()
            ->withoutGlobalScopes()
            ->with(['ownerContact', 'owners.contact'])
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('capture_kind', 'deeds_capture')
            ->whereNull('promoted_to_property_id')   // un-promoted only
            ->orderByDesc('last_enriched_at')
            ->paginate(30)
            ->withQueryString();

        return view('corex.deeds-capture.index', ['captures' => $captures]);
    }

    public function promote(Request $request, TrackedProperty $trackedProperty, TrackedPropertyMatchOrCreateService $matcher)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $trackedProperty->agency_id !== (int) $agencyId, 404);
        abort_if($trackedProperty->capture_kind !== 'deeds_capture', 404);

        if ($trackedProperty->promoted_to_property_id) {
            return redirect()->route('corex.deeds-capture.index')
                ->with('info', 'This capture was already promoted.');
        }

        $property = $matcher->promoteToStock($trackedProperty->id, (int) $user->id, array_filter([
            'title_deed_number' => $trackedProperty->title_deed_number,
        ]));

        // Link the deeds owner as the property's OWNER (contact_property role='owner').
        if ($trackedProperty->owner_contact_id) {
            DB::table('contact_property')->updateOrInsert(
                ['contact_id' => $trackedProperty->owner_contact_id, 'property_id' => $property->id],
                ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return redirect()->route('corex.deeds-capture.index')
            ->with('success', 'Promoted to a property and linked the owner. Open the property to continue.');
    }
}
