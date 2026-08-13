<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TvaContactCapture;
use App\Services\ContactDuplicateService;
use App\Services\Contacts\ContactIdentifierService;
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

        // TVA contact captures (2026-08-12) — only ones still carrying at least
        // one un-ingested item; a fully-ingested capture has nothing left for
        // the agent to act on and drops off the screen. Matched ones render
        // under their TrackedProperty card; standalone (no suspense record)
        // render as their own block, headed by name + surname + ID per spec.
        $tvaCaptures = TvaContactCapture::query()
            ->with(['items' => fn ($q) => $q->whereNull('ingested_at'), 'matchedContact'])
            ->where('agency_id', $agencyId)
            ->whereHas('items', fn ($q) => $q->whereNull('ingested_at'))
            ->orderByDesc('created_at')
            ->get();

        // ID-number match, computed FRESH at render time (2026-08-13) —
        // deliberately NOT keyed off tracked_property_id (a one-shot snapshot
        // taken at TVA-capture time in TvaContactCaptureController::ingestOne).
        // That snapshot only finds a match if the deeds/CMA capture already
        // existed at the moment the TVA scrape landed — a TVA capture done
        // BEFORE its matching CMA capture (or after the matched property was
        // dismissed/promoted) then NEVER matches, forever, since nothing
        // re-checks it. Re-deriving the match every render against the owner
        // ID numbers on properties currently visible on this screen makes the
        // nesting a live rule ("ID matches an owner → nest here") rather than
        // a point-in-time guess, and naturally self-heals once the missing
        // side of the capture shows up.
        $idNumbers = $tvaCaptures->pluck('id_number')->filter()->unique()->values();
        $ownerMatchesByIdNumber = \App\Models\Prospecting\TrackedPropertyOwner::query()
            ->whereIn('id_number', $idNumbers)
            ->whereIn('tracked_property_id', $captures->pluck('id'))
            ->get(['tracked_property_id', 'id_number'])
            ->groupBy('id_number');

        $tvaByProperty = collect();
        $tvaStandalone = collect();
        foreach ($tvaCaptures as $tvaCapture) {
            $matchedTpIds = $ownerMatchesByIdNumber->get($tvaCapture->id_number, collect())
                ->pluck('tracked_property_id')->unique();
            if ($matchedTpIds->isEmpty()) {
                $tvaStandalone->push($tvaCapture);
                continue;
            }
            foreach ($matchedTpIds as $tpId) {
                $tvaByProperty->put($tpId, $tvaByProperty->get($tpId, collect())->push($tvaCapture));
            }
        }

        return view('corex.deeds-capture.index', [
            'captures'       => $captures,
            'tvaByProperty'  => $tvaByProperty,
            'tvaStandalone'  => $tvaStandalone,
        ]);
    }

    /**
     * Agent-ticked ingest of TVA-captured phone/email rows into a Contact.
     * Target is either the capture's hard-matched contact (matched_contact_id,
     * from ContactDuplicateService at capture time), an agent-picked EXISTING
     * contact (the DR2 search picker), or a brand-new contact. Never merges,
     * never overwrites an existing contact's identity fields — only ADDS
     * phone/email rows via ContactPhone/ContactEmail + ContactIdentifierService
     * reconcile, same as any other multi-identifier writer.
     */
    public function ingestTva(Request $request, TvaContactCapture $tvaContactCapture, ContactIdentifierService $identifiers)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $tvaContactCapture->agency_id !== (int) $agencyId, 404);

        $data = $request->validate([
            'item_ids'   => 'required|array|min:1',
            'item_ids.*' => 'integer',
            'target'     => 'required|in:matched,existing,new',
            'contact_id' => 'nullable|integer', // required when target=existing
        ]);

        $items = $tvaContactCapture->items()
            ->whereIn('id', $data['item_ids'])
            ->whereNull('ingested_at')
            ->get();
        if ($items->isEmpty()) {
            return back()->with('info', 'Nothing to ingest — those items were already processed.');
        }

        $contact = match ($data['target']) {
            'matched' => Contact::withoutGlobalScopes()->find($tvaContactCapture->matched_contact_id),
            'existing' => Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->find($data['contact_id'] ?? null),
            'new' => Contact::create([
                'agency_id'             => $agencyId,
                'branch_id'             => $user->branch_id,
                'first_name'            => $tvaContactCapture->first_name ?: 'Contact',
                'last_name'             => $tvaContactCapture->surname ?? '',
                'phone'                 => '',
                'id_number'             => $tvaContactCapture->id_number,
                'id_number_captured_at' => now(),
                'id_number_source'      => 'tva',
                'created_by_user_id'    => (int) $user->id,
            ]),
        };
        abort_if(!$contact, 422, 'No target contact resolved.');

        $addedPhones = false;
        $addedEmails = false;
        foreach ($items as $item) {
            if ($item->type === 'email') {
                $normalised = strtolower(trim($item->value));
                $exists = $contact->emails()->whereRaw('LOWER(email) = ?', [$normalised])->exists();
                if (!$exists) {
                    $contact->emails()->create([
                        'agency_id' => $agencyId,
                        'email'     => $item->value,
                        'label'     => 'TVA capture' . ($item->link_date ? ' — linked ' . $item->link_date->format('Y-m-d') : ''),
                    ]);
                    $addedEmails = true;
                }
            } else {
                $normalised = app(ContactDuplicateService::class)->normalizePhone($item->value);
                $exists = $normalised && $contact->phones()->where('phone_normalised', $normalised)->exists();
                if (!$exists) {
                    $contact->phones()->create([
                        'agency_id' => $agencyId,
                        'phone'     => $item->value,
                        'label'     => 'TVA capture' . ($item->link_date ? ' — linked ' . $item->link_date->format('Y-m-d') : ''),
                    ]);
                    $addedPhones = true;
                }
            }
            $item->update(['ingested_at' => now(), 'ingested_contact_id' => $contact->id]);
        }

        if ($addedPhones) {
            $identifiers->reconcilePhones($contact->id);
        }
        if ($addedEmails) {
            $identifiers->reconcileEmails($contact->id);
        }

        return redirect()->route('corex.deeds-capture.index')
            ->with('success', 'Ingested ' . $items->count() . ' contact value' . ($items->count() > 1 ? 's' : '') . ' into ' . trim($contact->first_name . ' ' . $contact->last_name) . '.');
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
        // NOTE: 'address' is deliberately NOT set here — PropertyObserver::saving()
        // always recomputes it from unit_number/complex_name/street via
        // composeAddressFromParts() and silently overwrites any value passed to
        // create(), by design (keeps ~4,679 existing rows on one composition
        // rule). Setting complex_name/unit_number below is what actually
        // controls the real address; a redundant 'address' override here would
        // just be discarded and mislead a future reader.
        $displayAddress = implode(', ', array_filter([
            $trackedProperty->complex_name,
            $trackedProperty->section_number ? ('Section ' . $trackedProperty->section_number) : null,
            $trackedProperty->suburb,
        ])) ?: $trackedProperty->displayAddress();

        $property = $matcher->promoteToStock($trackedProperty->id, (int) $user->id, array_filter([
            'title_deed_number' => $trackedProperty->title_deed_number,
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
        //
        // CONTACT-SIDE SEAM (2026-08-14): this stays natural-person-only —
        // Api\DeedsCaptureController::isCompanyLikeOwner() already blocks a
        // company/CC/trust owner from ever reaching tracked_property_owners
        // (interim rule; proper company/entity handling is cc3's separate
        // contact-foundation investigation). The role='owner' link below is
        // deliberately just "whichever Contact IDs got resolved at capture
        // time" — entity-aware resolution (a company as its own legal-entity
        // record, a different role than 'owner' for a trustee, etc.) is
        // expected to slot in at the CAPTURE step once cc3's foundation
        // lands, not here; this loop doesn't need to change to accommodate
        // it, it just needs $ownerContactIds to start including entity
        // contacts once capture-time resolution produces them.
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

        return redirect()->route('corex.deeds-capture.index')
            ->with(
                'success',
                'Promoted to a property and linked the owner' . ($ownerContactIds->count() > 1 ? 's' : '') . '.'
            )
            // 2026-08-14 — the flash used to SAY "Open the property to continue"
            // with no actual link, dead-ending the user. success_link is a
            // separate, optional session key (see index.blade.php) so the
            // other flash('success', <plain string>) callers in this
            // controller (dismissProperty/dismissTva) are untouched.
            ->with('success_link', route('corex.properties.show', $property->id));
    }

    /**
     * Remove a deeds-capture property record from the screen (wrong details,
     * duplicate). SOFT delete only (Non-Negotiable #1, no hard deletes) —
     * TrackedProperty already carries SoftDeletes; this just sets
     * deleted_at, which the index() query's whereNull('deleted_at') then
     * excludes. Reversible by an admin (TrackedProperty::withTrashed()
     * ->find($id)->restore()); no in-app restore UI yet, not asked for.
     */
    public function dismissProperty(Request $request, TrackedProperty $trackedProperty)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $trackedProperty->agency_id !== (int) $agencyId, 404);
        abort_if($trackedProperty->capture_kind !== 'deeds_capture', 404);

        if ($trackedProperty->promoted_to_property_id) {
            return redirect()->route('corex.deeds-capture.index')
                ->with('info', 'This capture was already promoted — nothing to remove.');
        }

        $trackedProperty->delete();

        return redirect()->route('corex.deeds-capture.index')->with('success', 'Removed from the list.');
    }

    /**
     * Remove a TVA capture block from the screen (wrong details, duplicate —
     * e.g. an earlier capture superseded by a later one with the correct
     * name). SOFT delete — same reasoning as dismissProperty() above.
     */
    public function dismissTva(Request $request, TvaContactCapture $tvaContactCapture)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        abort_if((int) $tvaContactCapture->agency_id !== (int) $agencyId, 404);

        $tvaContactCapture->delete();

        return redirect()->route('corex.deeds-capture.index')->with('success', 'Removed from the list.');
    }
}
