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

        // Deeds-specific field mapping (2026-08-12) — promoteToStock()'s own
        // defaults are shared with the general MIC/prospecting promotion path
        // (TrackedPropertyController), so the deeds-only fields (scheme/
        // section, cadastral extent, a real address string, a price fallback)
        // are built HERE, as overrides, rather than changed in the shared
        // method. Was previously passing ONLY title_deed_number, so every
        // other field silently fell through to promoteToStock()'s generic
        // defaults — for a sectional-title deeds capture with no street
        // address, that default composed from street_number/street_name
        // alone, which are never populated by CMA, so it fell back to an
        // effectively-empty address/title and a R0 price.
        $addressParts = array_filter([
            $trackedProperty->complex_name,
            $trackedProperty->section_number ? ('Section ' . $trackedProperty->section_number) : null,
            $trackedProperty->suburb,
        ]);
        $displayAddress = $addressParts !== [] ? implode(', ', $addressParts) : $trackedProperty->displayAddress();

        $property = $matcher->promoteToStock($trackedProperty->id, (int) $user->id, array_filter([
            'title_deed_number' => $trackedProperty->title_deed_number,
            'address'           => $displayAddress,
            'title'             => $displayAddress,
            'complex_name'      => $trackedProperty->complex_name,   // = CMA scheme name
            'unit_number'       => $trackedProperty->section_number, // = CMA section number
            'erf_size_m2'       => $trackedProperty->cadastral_extent,
            // No "asking price" concept on a deeds capture — the last known
            // SOLD price is the only real price signal available; falls back
            // to promoteToStock()'s own 0-default when there isn't one.
            'price'             => $trackedProperty->last_known_sold_price,
        ], static fn ($v) => $v !== null && $v !== ''));

        // Link EVERY captured owner as the property's OWNER (contact_property
        // role='owner') — multi-owner support (2026-08-12), was only linking
        // the primary owner before. Contacts already exist from the CAPTURE
        // step (Api\DeedsCaptureController::ingestOne resolves/creates them);
        // sequencing per Johan — contact(s) first (already done at capture
        // time), property second (just created above), link last.
        $ownerContactIds = $trackedProperty->owners()->pluck('contact_id')->filter()->unique();
        if ($ownerContactIds->isEmpty() && $trackedProperty->owner_contact_id) {
            $ownerContactIds = collect([$trackedProperty->owner_contact_id]);
        }
        foreach ($ownerContactIds as $contactId) {
            DB::table('contact_property')->updateOrInsert(
                ['contact_id' => $contactId, 'property_id' => $property->id],
                ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return redirect()->route('corex.deeds-capture.index')->with(
            'success',
            'Promoted to a property and linked the owner' . ($ownerContactIds->count() > 1 ? 's' : '') . '. Open the property to continue.'
        );
    }
}
