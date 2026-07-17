<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\ViewingPack;
use App\Models\ViewingPackDocument;
use App\Models\ViewingPackProperty;
use App\Services\CommandCenter\CalendarEventService;
use App\Services\ViewingPack\ViewingPackAgentPdfService;
use App\Services\ViewingPack\ViewingPackBuyerPdfService;
use App\Services\ViewingPack\ViewingPackDocumentService;
use App\Services\ViewingPack\ViewingPackRedactionService;
use App\Services\ViewingPack\ViewingPackSelectionService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Viewing Pack CRUD (AT-XX, Step 2). Full create / read / update / archive /
 * recover. Tenancy is enforced structurally by AgencyScope on the ViewingPack
 * model — route-model binding of {viewingPack} returns 404 for another agency,
 * so a pack is never visible or mutable across agencies. "Delete" is a soft
 * delete (archive); restore recovers it (no hard deletes, ever).
 *
 * Selection / ordering / documents / redaction / PDFs are later steps — show()
 * renders the skeleton those steps fill in.
 */
class ViewingPackController extends Controller
{
    /**
     * AT-112 — row-level guard. The route middleware proves the caller MAY use
     * the viewing-pack feature at their action level; this proves they may touch
     * THIS pack under their data scope (own / branch / all). AgencyScope already
     * 404s cross-agency; this 403s an in-agency pack outside the caller's scope
     * (e.g. another agent's pack for an own-scope agent). Called at the top of
     * every single-pack action.
     */
    private function guardVisible(ViewingPack $pack): void
    {
        abort_unless($pack->isVisibleTo(request()->user()), 403, 'This viewing pack is outside your access scope.');
    }

    /** List packs for the current agency. ?archived=1 shows archived instead. */
    public function index(Request $request)
    {
        $showArchived = $request->boolean('archived');

        // AT-112 — row-level visibility on top of the route's permission gate:
        // agent sees own, branch manager the branch, admin all.
        $query = ViewingPack::query()
            ->visibleTo($request->user())
            ->with(['contact', 'agent'])
            ->withCount('viewingPackProperties')
            ->latest();

        if ($showArchived) {
            $query->onlyTrashed();
        }

        $packs = $query->paginate(25)->withQueryString();

        return view('command-center.viewing-packs.index', [
            'packs'        => $packs,
            'showArchived' => $showArchived,
        ]);
    }

    /** The pack workspace — Core Matches + ad-hoc search + selected list + docs. */
    public function show(ViewingPack $viewingPack, ViewingPackSelectionService $selection, ViewingPackDocumentService $docs)
    {
        $this->guardVisible($viewingPack);
        $viewingPack->load([
            'contact',
            'agent',
            'viewingPackProperties' => fn ($q) => $q->ordered()->with(['property', 'viewingPackDocuments']),
        ]);

        $buyer = $viewingPack->contact;
        // Canonical engine only — Core Matches via MatchingService/ClientMatchResolver.
        $coreMatches = $buyer ? $selection->coreMatchesFor($buyer) : collect();
        $selectedIds = $viewingPack->viewingPackProperties->pluck('property_id')->all();

        // Step 5a — per selected property: buyer-pack-ELIGIBLE attached documents
        // (resolver-filtered) + the ids currently ticked into the pack.
        $docPanel = $viewingPack->viewingPackProperties->map(fn (ViewingPackProperty $vpp) => [
            'vpp'         => $vpp,
            'eligible'    => $docs->eligibleDocumentsFor($vpp),
            'selectedIds' => $docs->selectedDocumentIds($vpp),
        ]);

        return view('command-center.viewing-packs.show', [
            'pack'        => $viewingPack,
            'coreMatches' => $coreMatches,
            'selectedIds' => $selectedIds,
            'docPanel'    => $docPanel,
        ]);
    }

    /**
     * Add a property to the pack. Source (core_match | ad_hoc) is computed
     * canonically by the service; a genuine non-match silently captures a
     * core_match_miss. The property is resolved through AgencyScope and double-
     * checked against the pack's agency — never cross-agency.
     */
    public function addProperty(Request $request, ViewingPack $viewingPack, ViewingPackSelectionService $selection)
    {
        $this->guardVisible($viewingPack);
        $data = $request->validate([
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')],
        ]);

        $property = Property::findOrFail($data['property_id']);
        abort_unless((int) $property->agency_id === (int) $viewingPack->agency_id, 404);

        $selection->addProperty($viewingPack, $property, $request->user()->id);

        return back()->with('success', 'Property added to the pack.');
    }

    /** Remove a selected property (soft delete; children cascade per Step 2). */
    public function removeProperty(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackSelectionService $selection)
    {
        $this->guardVisible($viewingPack);
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);

        $selection->removeProperty($viewingPackProperty);

        return back()->with('success', 'Property removed from the pack.');
    }

    /**
     * Persist the agent's manual drag order (spec §4 — no auto-routing). The
     * submitted `order` is the full list of this pack's property-row ids in the
     * new sequence; we rewrite sort_order as a COMPACT 1..N over exactly the
     * rows that belong to this pack, in one transaction, so a prior removal
     * leaves no gap and a foreign id can never touch another pack/agency.
     */
    public function reorderProperties(Request $request, ViewingPack $viewingPack)
    {
        $this->guardVisible($viewingPack);
        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data, $viewingPack) {
            // Current pack rows in their existing order (scoped to this pack +
            // agency). This is the authoritative membership set — a stray/foreign
            // id in the payload is filtered out, never written.
            $currentIds = $viewingPack->viewingPackProperties()->ordered()->pluck('id')->all();

            $submitted = array_values(array_filter(
                array_map('intval', $data['order']),
                fn ($id) => in_array($id, $currentIds, true),
            ));

            // Any pack rows the payload omitted keep their relative order at the
            // end — so the write is always a COHERENT FULL sequence, never partial.
            $remaining  = array_values(array_diff($currentIds, $submitted));
            $finalOrder = array_merge($submitted, $remaining);

            // Compact 1..N. Increment per row regardless of whether the value
            // actually changed (MySQL reports 0 affected for a no-op update —
            // gating on affected-count would collide positions).
            $position = 1;
            foreach ($finalOrder as $rowId) {
                $viewingPack->viewingPackProperties()
                    ->whereKey($rowId)
                    ->update(['sort_order' => $position]);
                $position++;
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Tick an eligible document into the buyer pack for one pack property
     * (Step 5a). The service rejects any document that isn't in the property's
     * buyer-pack-eligible set (attachment + eligibility + agency all enforced
     * there), so a forged/foreign/ineligible id 404s.
     */
    public function addDocument(Request $request, ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocumentService $docs)
    {
        $this->guardVisible($viewingPack);
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);

        $data = $request->validate([
            'document_id' => ['required', 'integer', Rule::exists('documents', 'id')],
        ]);

        $document = Document::findOrFail($data['document_id']);
        $docs->includeDocument($viewingPackProperty, $document);

        return back()->with('success', 'Document added to the buyer pack.');
    }

    /** Untick a document (soft-remove the row; no hard delete). */
    public function removeDocument(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument, ViewingPackDocumentService $docs)
    {
        $this->guardVisible($viewingPack);
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);
        abort_unless((int) $viewingPackDocument->viewing_pack_property_id === (int) $viewingPackProperty->id, 404);

        $docs->removeDocument($viewingPackDocument);

        return back()->with('success', 'Document removed from the buyer pack.');
    }

    /**
     * Rasterized source pages (base64 PNG + raster dims) for the on-screen
     * redaction tool (Step 5b). Authenticated + agency-scoped; nothing written
     * to disk — the unredacted preview only lives in this response.
     */
    public function redactionData(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument, ViewingPackRedactionService $redaction)
    {
        $this->guardVisible($viewingPack);
        $this->guardDocumentRow($viewingPack, $viewingPackProperty, $viewingPackDocument);

        try {
            return response()->json($redaction->pagePreviews($viewingPackDocument));
        } catch (\Throwable $e) {
            Log::error('ViewingPack redaction preview failed', ['vpd' => $viewingPackDocument->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'This document could not be opened for redaction.'], 422);
        }
    }

    /**
     * Burn the agent's boxes and (re)generate the flattened image-only artifact.
     * Boxes are raster-pixel coords per page: { "0": [{x,y,w,h}, …], … }.
     * On failure, nothing is written and a clear message is returned.
     */
    public function redactDocument(Request $request, ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument, ViewingPackRedactionService $redaction)
    {
        $this->guardVisible($viewingPack);
        $this->guardDocumentRow($viewingPack, $viewingPackProperty, $viewingPackDocument);

        $request->validate([
            'boxes'   => ['nullable', 'array'],
            'boxes.*' => ['nullable', 'array'],
        ]);

        try {
            $redaction->redact($viewingPackDocument, (array) $request->input('boxes', []));
        } catch (\Throwable $e) {
            Log::error('ViewingPack redaction failed', ['vpd' => $viewingPackDocument->id, 'error' => $e->getMessage()]);

            // AT-110 Bug 2 — the on-screen tool POSTs via fetch and shows this inline.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['error' => 'Could not redact this document: ' . $e->getMessage()], 422);
            }

            return back()->withErrors(['redaction' => 'Could not redact this document: ' . $e->getMessage()]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'redacted_file_path' => $viewingPackDocument->fresh()->redacted_file_path]);
        }

        return back()->with('success', 'Document redacted — a flattened copy was added to the buyer pack.');
    }

    /** Stream the flattened redacted artifact (authenticated + agency-scoped). */
    public function redactedFile(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument)
    {
        $this->guardVisible($viewingPack);
        $this->guardDocumentRow($viewingPack, $viewingPackProperty, $viewingPackDocument);

        $path = $viewingPackDocument->redacted_file_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Generate + stream the single buyer-facing PDF (Step 6). Tenancy is
     * enforced by AgencyScope on the {viewingPack} binding. This is ONE file;
     * the agent sheet (Step 7) is a separate download.
     */
    public function downloadBuyerPack(ViewingPack $viewingPack, ViewingPackBuyerPdfService $buyerPdf)
    {
        $this->guardVisible($viewingPack);
        return $buyerPdf->download($viewingPack);
    }

    /**
     * Generate + stream the SEPARATE agent sheet (Step 7). A distinct file from
     * the buyer pack (distinct service, path, route, filename) — never merged
     * (compliance spine §1). Eyes-only; carries the CONFIDENTIAL band.
     */
    public function downloadAgentSheet(ViewingPack $viewingPack, ViewingPackAgentPdfService $agentPdf)
    {
        $this->guardVisible($viewingPack);
        return $agentPdf->download($viewingPack);
    }

    /** Membership + tenancy guard shared by the document endpoints. */
    private function guardDocumentRow(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument): void
    {
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);
        abort_unless((int) $viewingPackDocument->viewing_pack_property_id === (int) $viewingPackProperty->id, 404);
    }

    /** Scoped property typeahead for ad-hoc selection (agency-bounded). */
    public function searchProperties(Request $request, ViewingPack $viewingPack)
    {
        $this->guardVisible($viewingPack);
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        // Canonical property search + label (fix-the-class): unit+complex aware,
        // multi-term token AND, newest-first. Property carries AgencyScope, so this
        // is already bounded to the user's agency.
        $rows = Property::query()
            ->searchAddress($q)
            ->with('agent')
            ->latest()
            ->limit(12)
            ->get();

        return response()->json($rows->map(fn (Property $p) => $p->toSearchResult([
            'ref'   => $p->property_number,
            'price' => $p->price,
        ])));
    }

    /**
     * Create a draft pack for a buyer from the buyer pipeline entry point.
     * The buyer is resolved through AgencyScope (findOrFail), so an agent can
     * only ever start a pack for a buyer in their own agency.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')],
        ]);

        // Scoped resolve — cross-agency contact_id 404s here, never creates.
        $buyer = Contact::findOrFail($data['contact_id']);

        $pack = ViewingPack::create([
            // agency_id is force-stamped by BelongsToAgency::creating from the
            // authenticated agent; we still pass the buyer's for non-auth paths.
            'agency_id'  => $buyer->agency_id,
            'contact_id' => $buyer->id,
            'agent_id'   => $request->user()->id,
            'status'     => ViewingPack::STATUS_DRAFT,
            'title'      => $this->defaultTitle($buyer),
        ]);

        // AT-111 R2 — if the agent arrived from a buyer-less appointment ("Prepare
        // viewing pack" -> pick a buyer), link this new pack back to that event and
        // carry its viewing date. Consumed once, agency-checked.
        if ($eventId = session()->pull('viewing_pack_link_event')) {
            $event = CalendarEvent::find($eventId);
            if ($event && (int) $event->agency_id === (int) $pack->agency_id) {
                $pack->forceFill([
                    'calendar_event_id' => $event->id,
                    'tour_at'           => $event->event_date,
                ])->save();
            }
        }

        return redirect()
            ->route('corex.viewing-packs.show', $pack)
            ->with('success', 'Viewing Pack started. Add properties to begin.');
    }

    // AT-XX — the pack's separate scheduler (schedule() + ViewingPackCalendarService)
    // was removed. Scheduling now reuses the SAME calendar prefill handoff as the
    // Schedule Viewing modal: the pack view links straight to
    // command-center.calendar?prefill_class=viewing&prefill_contact_id&prefill_attendees&prefill_properties
    // (built server-side in show.blade from the pack's ordered properties + buyer).
    // No parallel scheduling logic remains.

    /**
     * Edit pack metadata (title / status) AND — AT-111 R2 (Johan's forward sync) —
     * on save, link the pack to its calendar appointment (already-linked, or matched
     * by buyer + viewing date) and push the pack's properties + buyer onto that
     * event, so the appointment shows the properties, parties, and the pack download
     * buttons. No-op when there is no appointment to sync.
     */
    public function update(Request $request, ViewingPack $viewingPack, CalendarEventService $calendar)
    {
        $this->guardVisible($viewingPack);
        $data = $request->validate([
            'title'  => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(ViewingPack::STATUSES)],
        ]);

        $viewingPack->update([
            'title'  => $data['title'] ?? $viewingPack->title,
            'status' => $data['status'],
        ]);

        $event = $this->linkedOrMatchedEvent($viewingPack->fresh());
        if ($event) {
            $this->pushPackToEvent($viewingPack->fresh(), $event, $calendar, $request->user());
        }

        return back()->with('success', $event
            ? 'Viewing Pack saved — the linked appointment now shows its properties, parties and downloads.'
            : 'Viewing Pack saved.');
    }

    /** Archive (soft delete). Children cascade out via the model layer. */
    public function destroy(ViewingPack $viewingPack)
    {
        $this->guardVisible($viewingPack);
        $viewingPack->delete();

        return redirect()
            ->route('corex.viewing-packs.index')
            ->with('success', 'Viewing Pack archived. You can recover it from the archived list.');
    }

    /** Recover an archived pack (and the children it took down). */
    public function restore(ViewingPack $viewingPack)
    {
        $this->guardVisible($viewingPack);
        $viewingPack->restore();

        return redirect()
            ->route('corex.viewing-packs.show', $viewingPack)
            ->with('success', 'Viewing Pack recovered.');
    }

    /**
     * AT-111 direction 2 — LAUNCH a viewing pack from an EXISTING calendar event
     * (schedule-now-prep-later). If the event already has a linked pack, open it;
     * otherwise create one linked to the event, seeded with the event's buyer.
     *
     * Reuses the event's own agency/branch/contact — no parallel scheduling, no
     * new event. The forward pack→calendar prefill handoff is unchanged; this is
     * purely the reverse direction the AT-111 workflow needs.
     */
    public function launchFromEvent(Request $request, \App\Models\CommandCenter\CalendarEvent $calendarEvent)
    {
        abort_unless(app(\App\Services\CommandCenter\Calendar\CalendarVisibilityResolver::class)
            ->canSee($calendarEvent, $request->user()), 403);

        // Already linked → just open it (idempotent — never a second pack per event).
        $existing = $calendarEvent->viewingPack()->first();
        if ($existing) {
            $this->guardVisible($existing);

            return redirect()->route('corex.viewing-packs.show', $existing);
        }

        // Resolve the event's buyer: the direct contact_id, else a buyer/attendee link.
        $contactId = $calendarEvent->contact_id;
        if (! $contactId) {
            $contactId = DB::table('calendar_event_links')
                ->where('calendar_event_id', $calendarEvent->id)
                ->where('linkable_type', Contact::class)
                ->whereIn('role', ['buyer_contact', 'attendee'])
                ->value('linkable_id');
        }
        // AT-111 R2 — no buyer on the appointment: don't dead-end (Johan hit a 422
        // on a bare time-slot). Stash the event and send the agent to the buyer
        // pipeline to pick one; the next pack they build links back to THIS
        // appointment (consumed once, in store()).
        if (! $contactId) {
            session(['viewing_pack_link_event' => $calendarEvent->id]);

            return redirect()->route('command-center.buyers.pipeline')
                ->with('info', 'This appointment has no buyer yet. Open the buyer and click "Build Viewing Pack" — the pack will link to this appointment.');
        }

        // AT-111 — resolve the buyer WITHOUT branch/contact scopes: the id came
        // from the event itself (already agency-consistent), and a linked buyer may
        // legitimately sit outside the acting agent's branch (the calendar panel
        // resolves linked contacts the same way). Verify agency to stay tenant-safe.
        $eventAgencyId = (int) ($calendarEvent->agency_id ?? $request->user()->effectiveAgencyId());
        $buyer = Contact::withoutGlobalScopes()->find($contactId);
        abort_unless($buyer && (int) $buyer->agency_id === $eventAgencyId, 422, 'The appointment\'s buyer contact could not be found.');

        $pack = ViewingPack::create([
            'agency_id'         => $calendarEvent->agency_id ?? $buyer->agency_id,
            'branch_id'         => $calendarEvent->branch_id,   // else BelongsToBranch fills from actor
            'contact_id'        => $buyer->id,
            'agent_id'          => $request->user()->id,
            'calendar_event_id' => $calendarEvent->id,
            'tour_at'           => $calendarEvent->event_date,
            'status'            => ViewingPack::STATUS_DRAFT,
            'title'             => $this->defaultTitle($buyer),
        ]);

        return redirect()
            ->route('corex.viewing-packs.show', $pack)
            ->with('success', 'Viewing Pack launched from the appointment. Add the properties you can show, then Save to update the appointment.');
    }

    /**
     * AT-111 direction 3 — UPDATE APPOINTMENT: push this pack's final, drag-ordered
     * properties onto the LINKED calendar event, in place. Reuses
     * CalendarEventService::syncManualEventLinks (the SAME link-sync the calendar's
     * own edit path uses) — never a parallel scheduler, never a new event.
     */
    public function updateAppointment(Request $request, ViewingPack $viewingPack, CalendarEventService $calendar)
    {
        $this->guardVisible($viewingPack);
        abort_unless($viewingPack->calendar_event_id, 422, 'This pack is not linked to an appointment yet.');

        $event = CalendarEvent::findOrFail($viewingPack->calendar_event_id);
        $this->pushPackToEvent($viewingPack, $event, $calendar, $request->user());

        $n = $viewingPack->viewingPackProperties()->count();

        return back()->with('success', 'Appointment updated with the pack\'s properties & parties (' . $n . ').');
    }

    /**
     * AT-111 R2 — the calendar appointment this pack should sync to: the one it is
     * already linked to, else the single viewing appointment for this buyer on the
     * pack's viewing date. Returns null when there is nothing to link (never guesses
     * across multiple matches; never creates an event).
     */
    private function linkedOrMatchedEvent(ViewingPack $pack): ?CalendarEvent
    {
        if ($pack->calendar_event_id) {
            return CalendarEvent::find($pack->calendar_event_id);
        }
        if (! $pack->tour_at || ! $pack->contact_id) {
            return null;
        }

        $matches = CalendarEvent::query()
            ->where('category', 'viewing')
            ->whereDate('event_date', $pack->tour_at->toDateString())
            ->where(function ($q) use ($pack) {
                $q->where('contact_id', $pack->contact_id)
                    ->orWhereIn('id', function ($sub) use ($pack) {
                        $sub->select('calendar_event_id')->from('calendar_event_links')
                            ->where('role', 'buyer_contact')
                            ->where('linkable_type', Contact::class)
                            ->where('linkable_id', $pack->contact_id);
                    });
            })
            ->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * AT-111 R2 — push a pack onto its appointment: link it, sync the ordered
     * properties (subject_property + scalar primary), and ensure the buyer is a
     * party (non-destructive — never wipes the agent or other attendees). The event
     * panel then renders the properties, parties, and the pack download buttons.
     */
    private function pushPackToEvent(ViewingPack $pack, CalendarEvent $event, CalendarEventService $calendar, \App\Models\User $user): void
    {
        if ((int) $pack->calendar_event_id !== (int) $event->id) {
            $pack->forceFill(['calendar_event_id' => $event->id])->save();
        }

        $propertyIds = $pack->viewingPackProperties()->ordered()->pluck('property_id')->map(fn ($i) => (int) $i)->all();
        $calendar->update($event, ['property_id' => $propertyIds[0] ?? $event->property_id]);
        $calendar->syncManualEventLinks($event, ['category' => $event->category, 'property_ids' => $propertyIds], $user);

        if ($pack->contact_id) {
            $exists = DB::table('calendar_event_links')
                ->where('calendar_event_id', $event->id)
                ->where('role', 'buyer_contact')
                ->where('linkable_type', Contact::class)
                ->where('linkable_id', $pack->contact_id)
                ->exists();
            if (! $exists) {
                DB::table('calendar_event_links')->insert([
                    'agency_id'          => $event->agency_id,
                    'calendar_event_id'  => $event->id,
                    'linkable_type'      => Contact::class,
                    'linkable_id'        => $pack->contact_id,
                    'role'               => 'buyer_contact',
                    'created_by_user_id' => $user->id,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }
            if (! $event->contact_id) {
                $event->forceFill(['contact_id' => $pack->contact_id])->save();
            }
        }
    }

    /** Auto title: "Viewing Pack — {Buyer} — {d M Y}". */
    private function defaultTitle(Contact $buyer): string
    {
        $name = trim((string) ($buyer->full_name ?? trim(($buyer->first_name ?? '') . ' ' . ($buyer->last_name ?? ''))));
        if ($name === '') {
            $name = 'Buyer #' . $buyer->id;
        }

        return 'Viewing Pack — ' . $name . ' — ' . now()->format('d M Y');
    }
}
