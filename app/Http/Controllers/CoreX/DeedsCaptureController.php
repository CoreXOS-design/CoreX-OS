<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\P24Suburb;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TvaContactCapture;
use App\Models\PropertyNote;
use App\Services\ContactDuplicateService;
use App\Services\Contacts\ContactIdentifierService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            // DEEDS BUG 1 fix (2026-08-19) — surface on the EVENT marker
            // (deeds_captured_at IS NOT NULL), not just the pipeline
            // classification (capture_kind='deeds_capture'). capture_kind is
            // deliberately left alone by a deeds capture that lands on an
            // EXISTING prospecting/P24 lead (so the lead stays in MIC
            // Opportunities), but the capture itself must still show here —
            // otherwise it silently vanishes. Either signal is enough:
            // capture_kind alone still catches historical rows captured
            // before deeds_captured_at existed and never re-captured since.
            ->where(function ($q) {
                $q->where('capture_kind', 'deeds_capture')
                    ->orWhereNotNull('deeds_captured_at');
            })
            ->whereNull('promoted_to_property_id')   // un-promoted only
            // PITCHED-state (Johan 2026-08-14) — this is a SUSPENSE screen, not a deed-import host.
            // Drop deeds already CONSUMED by a worked (PITCHED) item, via EITHER signal:
            //  (a) the deed's owner(s) are now seller(s) on a pitched property (the fundamental
            //      "these people were worked" signal — covers per-owner "+ Link as seller"), or
            //  (b) the deed was explicitly linked to a pitched listing (linked_deed).
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tracked_property_owners as tpo')
                    ->join('contact_property as cp', fn ($j) => $j->on('cp.contact_id', '=', 'tpo.contact_id')->where('cp.role', 'seller'))
                    ->join('prospecting_listings as pl', fn ($j) => $j->on('pl.matched_property_id', '=', 'cp.property_id')->whereNotNull('pl.pitched_at')->whereNull('pl.deleted_at'))
                    ->whereColumn('tpo.tracked_property_id', 'tracked_properties.id')
                    ->whereNotNull('tpo.contact_id');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('prospecting_listings as pl2')
                    ->whereColumn('pl2.linked_deed_tracked_property_id', 'tracked_properties.id')
                    ->whereNotNull('pl2.pitched_at')
                    ->whereNull('pl2.deleted_at');
            })
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

        // 2026-08-19 (Johan, .ai/specs/deeds-capture.md §6 Part B) — "users
        // will see enriched for a current property but wont know what
        // happened to the data. so rather show it in deeds." Show what the
        // MOST RECENT deeds capture did — not the most recent one that
        // happened to change something. Reaching back past a no-op re-capture
        // to an older capture's changes would show a "correction" for a value
        // that has been stable since; if the latest capture changed nothing,
        // the honest card shows nothing (unchanged fields are noise, per
        // Johan — and so is a stale correction from a capture that isn't the
        // one that just ran).
        $fieldChangesByTp = [];
        foreach ($captures as $tp) {
            $chain = $tp->source_chain ?? [];
            $latestDeedsEntry = null;
            foreach ($chain as $entry) {
                if (($entry['type'] ?? null) === 'deeds_capture') {
                    $latestDeedsEntry = $entry; // append-only chain — last match is the most recent
                }
            }
            if ($latestDeedsEntry === null || empty($latestDeedsEntry['field_changes'])) {
                continue;
            }
            $changes = $latestDeedsEntry['field_changes'];
            $fieldChangesByTp[$tp->id] = [
                'date'     => $latestDeedsEntry['date'] ?? null,
                'filled'   => array_values(array_filter($changes, fn ($c) => ($c['change_type'] ?? null) === 'filled')),
                'replaced' => array_values(array_filter($changes, fn ($c) => ($c['change_type'] ?? null) === 'replaced')),
                // 'cleared' (2026-08-19, cc3 — TrackedPropertyMatchOrCreateService::
                // enrich()'s 4th outcome): a stored placeholder (e.g. property_type
                // stuck at the literal "-") got wiped back to null because THIS
                // capture also only had a placeholder to offer. A real, visible
                // change to the record — must not be invisible just because
                // "filled"/"replaced" don't fit it.
                'cleared'  => array_values(array_filter($changes, fn ($c) => ($c['change_type'] ?? null) === 'cleared')),
            ];
        }

        return view('corex.deeds-capture.index', [
            'captures'         => $captures,
            'tvaByProperty'    => $tvaByProperty,
            'tvaStandalone'    => $tvaStandalone,
            'fieldChangesByTp' => $fieldChangesByTp,
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

        $result = $this->applyTvaItems($tvaContactCapture, $data['item_ids'], $data['target'], $data['contact_id'] ?? null, $user, $agencyId, $identifiers);
        if ($result === null) {
            return back()->with('info', 'Nothing to ingest — those items were already processed.');
        }
        [$contact, $itemsCount] = [$result['contact'], $result['count']];

        return redirect()->route('corex.deeds-capture.index')
            ->with('success', 'Ingested ' . $itemsCount . ' contact value' . ($itemsCount > 1 ? 's' : '') . ' into ' . trim($contact->first_name . ' ' . $contact->last_name) . '.')
            // 2026-08-17 — ingestTva() never got the success_link treatment
            // promote() got on 2026-08-14 (see that method's comment): the
            // flash named the contact but gave the agent nothing to click,
            // so after a successful ingest the browser just sat on the same
            // list screen with no visible change — read by Johan as "the
            // contact details did not load." The contact IS created/updated
            // correctly (verified: exact ticked numbers land on it, nothing
            // else) — this was purely a missing link, not a data bug.
            ->with('success_link', route('corex.contacts.show', $contact->id))
            ->with('success_link_label', 'Open contact →');
    }

    /**
     * Shared by ingestTva() (standalone — the genuinely different
     * enrich-without-promote case, e.g. a TVA scrape for an owner whose
     * property was already promoted) and promote() (the merged one-button
     * case, 2026-08-19 Johan). Applies a TVA capture's ticked item ids onto a
     * target Contact — creates the phone/email rows, discards the
     * still-pending REST of this SAME capture (Johan's one-shot-per-capture
     * rule: "the balance of the numbers can be dumped"), reconciles
     * identifiers. Throws (abort_if 422) when no target contact resolves —
     * inside promote()'s DB::transaction() that rolls back the WHOLE promote,
     * which is the point (a half-succeeded promote is worse than a failed
     * one).
     *
     * @return array{contact: Contact, count: int}|null null = nothing left to ingest (already processed)
     */
    private function applyTvaItems(
        TvaContactCapture $tvaContactCapture,
        array $itemIds,
        string $target,
        ?int $contactId,
        $user,
        int $agencyId,
        ContactIdentifierService $identifiers
    ): ?array {
        $items = $tvaContactCapture->items()
            ->whereIn('id', $itemIds)
            ->whereNull('ingested_at')
            ->get();
        if ($items->isEmpty()) {
            return null;
        }

        $contact = match ($target) {
            'matched' => Contact::withoutGlobalScopes()->find($tvaContactCapture->matched_contact_id),
            'existing' => Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->find($contactId),
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

        // Johan: "the balance of the numbers can be dumped — if it's not
        // active we don't need them." Ticking a subset and submitting is a
        // one-shot decision on THIS capture, not a partial one — anything
        // left un-ticked at this point is discarded, not left pending for a
        // future ingest. Discarded == ingested_at set / ingested_contact_id
        // left null, so it drops off index()'s whereNull('ingested_at')
        // pending list exactly like a real ingest does, without a hard
        // delete (non-negotiable #1) — the row (and its value) stays in the
        // table, just marked resolved-without-a-contact.
        $tvaContactCapture->items()
            ->whereNotIn('id', $itemIds)
            ->whereNull('ingested_at')
            ->update(['ingested_at' => now()]);

        return ['contact' => $contact, 'count' => $items->count()];
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
        // section, cadastral extent, a real address string) are built HERE,
        // as overrides, rather than changed in the shared method. Was
        // previously passing ONLY title_deed_number, so every other field
        // silently fell through to promoteToStock()'s generic defaults — for
        // a sectional-title deeds capture with no street address, that
        // default composed from street_number/street_name alone, which are
        // never populated by CMA, so it fell back to an effectively-empty
        // address/title.
        // NOTE: 'address' is deliberately NOT set here — PropertyObserver::saving()
        // always recomputes it from unit_number/complex_name/street via
        // composeAddressFromParts() and silently overwrites any value passed to
        // create(), by design (keeps ~4,679 existing rows on one composition
        // rule). Setting complex_name/unit_number below is what actually
        // controls the real address; a redundant 'address' override here would
        // just be discarded and mislead a future reader.

        // Garbage-placeholder guard (2026-08-18) — mirrors index.blade.php's
        // proven $hasRealSection/$isSectional check (2026-08-14, CONFIRMED
        // live on 56 Avenue Svea / 53 Broadway / this capture's own property
        // 6100): the extension sometimes leaks the source page's placeholder
        // LABEL text ("Flat number") into section_number when the field was
        // genuinely empty on a FREEHOLD record — no scheme_name is present,
        // so this is not the complex→freehold state-bleed v3.4.0 fixed
        // (Skippers/Section 3 carrying into an unrelated freehold), it's a
        // separate empty-field-scrapes-as-placeholder defect. A bare
        // truthiness check on section_number mislabels the property as
        // sectional and corrupts unit_number/address with the placeholder
        // string, so every promote-side use of section_number below is
        // gated on $hasRealSection, never raw truthiness.
        $hasRealSection = filled($trackedProperty->section_number) && preg_match('/\d/', (string) $trackedProperty->section_number);
        $hasSectionalSignal = filled($trackedProperty->complex_name)
            || filled($trackedProperty->scheme_name)
            || filled($trackedProperty->scheme_number)
            || $hasRealSection;
        $isSectional = $hasSectionalSignal; // kept as the existing name for the address/unit_number logic below

        // property_type fix (2026-08-19, mapping-proof audit) — CONFIRMED
        // live on real captures (TrackedProperty 11563/9636/9641): the old
        // `$isSectional ? 'Apartment / Flat' : 'House'` binary ALWAYS picked
        // one, even when $hasSectionalSignal was false ONLY because the
        // extension's pre-v3.5.0 stale-residue bug had wiped a genuinely
        // sectional capture's own scheme_name/scheme_number/section_number —
        // TP 11563 (Uvongo Breeze, a real sectional unit) would have promoted
        // as 'House'. A second, independent freehold signal (erf_number) is
        // now required to confidently call it a freehold — and when NEITHER
        // signal is present, or (a page-state anomaly) BOTH are, this no
        // longer guesses: property_type is left as '' (the property edit
        // form's own established "Choose a type…" sentinel — see
        // resources/views/corex/properties/wizard.blade.php:173), which
        // TitleTypeClassifier::fromPropertyType() already handles cleanly
        // (returns null, no crash — verified by reading that class). Per
        // Johan: "a blank an agent can correct, a confidently wrong type
        // they will never notice."
        $hasFreeholdSignal = filled($trackedProperty->erf_number);
        $propertyType = match (true) {
            $hasSectionalSignal && !$hasFreeholdSignal => 'Apartment / Flat',
            $hasFreeholdSignal && !$hasSectionalSignal => 'House',
            default => '', // neither signal, or both (contradictory) — do not guess
        };

        // Title/display-address fix (2026-08-19, mapping-proof audit) —
        // spec §6.3. CONFIRMED live: the old version only ever combined
        // complex_name + "Section N" + suburb, so a FREEHOLD (no
        // complex_name, no section) always collapsed to JUST the suburb
        // ("BEACON ROCKS" for 39 Bairn Street) — the street address was
        // silently dropped on EVERY freehold deeds-capture promotion, and
        // the `?: $trackedProperty->displayAddress()` fallback the old
        // comment relied on never actually fired, because `suburb` alone
        // already made the array_filter()'d string truthy. Rebuilt to match
        // Johan's exact §6.3 formats: freehold "Erf {n}, {street}, {suburb}",
        // sectional "Section {n}, {scheme}, {street}, {suburb}" — both now
        // always include the street line when present.
        $streetLine = trim(($trackedProperty->street_number ?? '') . ' ' . ($trackedProperty->street_name ?? ''));
        $displayAddress = $isSectional
            ? implode(', ', array_filter([
                $hasRealSection ? ('Section ' . $trackedProperty->section_number) : null,
                $trackedProperty->complex_name,
                $streetLine ?: null,
                $trackedProperty->suburb,
            ]))
            : implode(', ', array_filter([
                filled($trackedProperty->erf_number) ? ('Erf ' . $trackedProperty->erf_number) : null,
                $streetLine ?: null,
                $trackedProperty->suburb,
            ]));
        $displayAddress = $displayAddress ?: $trackedProperty->displayAddress();

        // Suburb → town resolution (2026-08-18) — a deeds capture's raw `town`
        // is the district MUNICIPALITY (e.g. "Ray Nkonyeni"), not the actual
        // town an agent/buyer recognises ("Margate"). Resolve the real town +
        // the P24 suburb/city FK chain the rest of the app relies on (map
        // pins, portal sync, matching) via the same p24_suburbs table
        // AppliesP24Location trusts. Soft — never blocks promotion; when the
        // captured suburb text doesn't match a known P24 suburb, the raw town
        // is kept and p24_suburb_mismatch is flagged for the nightly
        // SyncP24Locations/ReconcileP24Suburbs reconcile to pick up later.
        $p24Suburb = $trackedProperty->suburb ? P24Suburb::lookup($trackedProperty->suburb) : null;
        $p24City = $p24Suburb?->city;

        // Extent fix (2026-08-19, mapping-proof audit) — spec §6.4, binding.
        // CONFIRMED live (TrackedProperty 9635, a real sectional unit): the
        // old `'erf_size_m2' => $trackedProperty->cadastral_extent` wrote 70
        // (THE SECTION'S OWN size) into the property's ERF-size column.
        // `cadastral_extent` is NOT an erf size for either title type — it
        // holds a FREEHOLD's own "Cadastral extent" OR a SECTIONAL unit's own
        // "Section extent" (type-dependent single storage slot; see
        // buildDeedsCapturePayload() on the extension side and
        // .ai/specs/deeds-capture.md §6.4's "never merge into one size field"
        // rule) — writing it into erf_size_m2 is exactly the cross-type
        // substitution the spec forbids, for BOTH types. There is no captured
        // field for a freehold's true "Extent" (erf size) yet — §2's payload
        // contract has no slot for it — so erf_size_m2 is deliberately left
        // UNSET below (absent is absent, never substituted) until that lands.
        // size_m2 (the correct sectional unit-size field, per §6.4) now gets
        // the sectional's own extent from that same slot — it was previously
        // wired ONLY to floor_size_m2, a column deeds-capture never
        // populates, so size_m2 was ALWAYS null for every sectional
        // promotion regardless of how good the capture was.
        $overrides = array_filter([
            'title_deed_number' => $trackedProperty->title_deed_number,
            'title'             => $displayAddress,
            'complex_name'      => $trackedProperty->complex_name,   // = CMA scheme name
            'size_m2'           => $isSectional ? $trackedProperty->cadastral_extent : $trackedProperty->floor_size_m2,
            'town'              => $p24City?->name ?? $trackedProperty->town,
            'city'              => $p24City?->name,
            'p24_suburb_id'     => $p24Suburb?->id,
            'p24_city_id'       => $p24Suburb?->p24_city_id,
        ], static fn ($v) => $v !== null && $v !== '');

        // Always-set overrides — must win even when "empty", so
        // promoteToStock()'s own defaults (which re-derive from these SAME
        // raw tracked_property fields) can never reintroduce the garbage
        // these overrides exist to strip. array_filter above would drop a
        // null/false value and let that fallthrough happen.
        $overrides['unit_number']         = $hasRealSection ? $trackedProperty->section_number : null; // = CMA section number, garbage-guarded
        $overrides['property_type']       = $propertyType;
        $overrides['p24_suburb_mismatch'] = $p24City === null;

        // One-button promote+ingest (2026-08-19, Johan, verbatim from last night):
        // "the user should now tick the contact details they want, and clicking
        // the promote to property + contact should be clicked, not click ingest
        // at the bottom... users will instantly get confused if they click
        // promote and it did not capture contact details, and same if they
        // click ingest and the numbers dissapear they immediately will call it
        // broken." Ticked TVA numbers, if any, are keyed by TVA capture id under
        // `tva` — the index.blade.php card's nested TVA blocks submit into THIS
        // same form via the HTML5 form="" attribute rather than their own
        // separate <form>, so one click carries both. Ticking nothing is a
        // legitimate, unwarned path — property + owner only, no numbers.
        $tvaInput = $request->validate([
            'tva'                 => 'nullable|array',
            'tva.*.item_ids'      => 'nullable|array',
            'tva.*.item_ids.*'    => 'integer',
            'tva.*.target'        => 'nullable|in:matched,existing,new',
            'tva.*.contact_id'    => 'nullable|integer',
        ])['tva'] ?? [];

        // Both writes in ONE transaction — both succeed or neither does. A
        // promote that half-works (property created, ticked numbers silently
        // failed) is worse than one that fails outright and can be retried.
        // promoteToStock()'s own DB::transaction() composes cleanly nested
        // inside this one (MySQL savepoints via Laravel's transaction nesting).
        $identifiers = app(ContactIdentifierService::class);
        [$property, $ownerContactIds, $tvaContactsTouched] = DB::transaction(function () use (
            $trackedProperty, $matcher, $overrides, $agencyId, $user, $tvaInput, $identifiers
        ) {
            // No "asking price" concept on a deeds capture — Johan (2026-08-18):
            // "we cannot prefill the price - remove that... if anything, save it
            // on the logs or notes but not as the price." Was:
            // 'price' => $trackedProperty->last_known_sold_price, which silently
            // prefilled the SELLING price with a stale HISTORICAL sale price
            // (property 6100: R420,000 from 2008-08-28). Falls through to
            // promoteToStock()'s own 0-default; the sale price is logged as a
            // PropertyNote below instead.
            $property = $matcher->promoteToStock($trackedProperty->id, (int) $user->id, $overrides);

            if ($trackedProperty->last_known_sold_price) {
                PropertyNote::create([
                    'agency_id'   => $agencyId,
                    'property_id' => $property->id,
                    'user_id'     => $user->id,
                    'content'     => 'Last sale price (deeds history): R'
                        . number_format((float) $trackedProperty->last_known_sold_price, 0, '.', ',')
                        . ($trackedProperty->last_known_sold_date
                            ? ' on ' . Carbon::parse($trackedProperty->last_known_sold_date)->format('Y-m-d')
                            : '')
                        . '. Not the current asking price.',
                ]);
            }

            // Link CURRENT owners as the property's OWNER (contact_property
            // role='owner') — multi-owner support (2026-08-12), extended
            // 2026-08-19 for ownership-history captures (.ai/specs/deeds-capture.md
            // §7.11). Contacts already exist from the CAPTURE step
            // (Api\DeedsCaptureController::ingestOne / captureOwnershipHistory
            // resolve/create them, entity-aware — trust/company registration
            // numbers route to entity_reg_no, never id_number); sequencing per
            // Johan — contact(s) first (already done at capture time), property
            // second (just created above), link last.
            //
            // PAST owners (a prior transfer's seller) are structurally EXCLUDED by
            // this filter, not by a display-layer check somewhere else — a row
            // with ownership_status='past' (or null, an unclassified §7.9 case-4
            // row) never reaches $ownerContactIds, so there is no code path here
            // that could ever link one as this property's owner. Rows from the
            // simple (non-history) capture path all default to
            // ownership_status='current' at the DB level, so this filter is a
            // no-op for every capture that predates §7 — the single-owner path is
            // unchanged.
            $ownerContactIds = $trackedProperty->owners()
                ->where('ownership_status', \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_CURRENT)
                ->pluck('contact_id')->filter()->unique();
            if ($ownerContactIds->isEmpty() && $trackedProperty->owner_contact_id) {
                $ownerContactIds = collect([$trackedProperty->owner_contact_id]);
            }
            foreach ($ownerContactIds as $contactId) {
                DB::table('contact_property')->updateOrInsert(
                    ['contact_id' => $contactId, 'property_id' => $property->id],
                    ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()],
                );
            }

            // Ticked TVA numbers land on the contact in this SAME transaction.
            // A capture id never present in $tvaInput at all (the agent never
            // touched that block) is left completely untouched — only captures
            // the agent actually ticked into get the "balance can be dumped"
            // one-shot treatment (applyTvaItems()'s discard-the-rest).
            $tvaContactsTouched = [];
            foreach ($tvaInput as $captureId => $captureData) {
                $itemIds = array_filter($captureData['item_ids'] ?? []);
                if ($itemIds === []) {
                    continue; // this block was rendered but nothing was ticked in it
                }
                $capture = TvaContactCapture::where('id', $captureId)->where('agency_id', $agencyId)->first();
                if (!$capture) {
                    continue; // stale/foreign id — never trust the client's capture id blindly
                }
                $result = $this->applyTvaItems(
                    $capture,
                    $itemIds,
                    $captureData['target'] ?? 'new',
                    $captureData['contact_id'] ?? null,
                    $user,
                    $agencyId,
                    $identifiers
                );
                if ($result) {
                    $tvaContactsTouched[] = $result;
                }
            }

            return [$property, $ownerContactIds, $tvaContactsTouched];
        });

        // One outcome, one message — Johan: a user must never wonder "did it
        // also grab the numbers?" after a single click.
        $numbersCount = array_sum(array_column($tvaContactsTouched, 'count'));
        $summary = 'Promoted to a property and linked the owner' . ($ownerContactIds->count() > 1 ? 's' : '') . '.';
        if ($numbersCount > 0) {
            $summary .= ' Ingested ' . $numbersCount . ' contact value' . ($numbersCount > 1 ? 's' : '') . '.';
        }

        return redirect()->route('corex.deeds-capture.index')
            ->with('success', $summary)
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
