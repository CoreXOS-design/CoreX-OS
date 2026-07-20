<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\DocumentType;
use App\Models\PropertySettingItem;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Services\ContactDuplicateService;
use App\Services\PermissionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user         = auth()->user();
        $dataScope    = PermissionService::getDataScope($user, 'contacts');
        $canPickAgent = in_array($dataScope, ['all', 'branch']);

        // Agent filter: always default to the current user's own contacts on a
        // fresh visit. An explicit ?agent_id= (e.g. "All", or another agent)
        // applies for that browse only and is NOT persisted across visits.
        if ($request->has('agent_id')) {
            $filterAgentId = $request->query('agent_id', '');
        } elseif ($canPickAgent) {
            $filterAgentId = (string) $user->id;
        } else {
            $filterAgentId = '';
        }

        $query = Contact::with(['type', 'createdBy'])->orderBy('last_name')->orderBy('first_name');

        // AT-91 — an EXPLICIT agent pick keys off contacts.agent_id (the
        // operational responsible agent), NOT created_by_user_id (immutable
        // creator). This reconciles the contacts list with the WhatsApp Outreach
        // Summary board, which attributes rows by agent_id, so a board cell count
        // and its drilled list are identical. 'unassigned' → contacts with no
        // responsible agent. The no-pick default-narrowing paths are left on the
        // original created_by basis (and ContactScope's own-row enforcement) so
        // the everyday contacts page is unchanged.
        if ($canPickAgent) {
            if ($filterAgentId === 'unassigned') {
                $query->whereNull('agent_id');
            } elseif ($filterAgentId !== '' && $filterAgentId !== 'all') {
                $query->where('agent_id', (int) $filterAgentId);
            } elseif ($dataScope === 'branch' && $user->branch_id) {
                $query->whereHas('createdBy', fn($q) => $q->where('branch_id', $user->branch_id));
            }
            // 'all' scope with no filter = show all contacts
        } else {
            // 'own' scope: agents see only their own (ContactScope also enforces this)
            $query->where('created_by_user_id', $user->id);
        }

        // AT-91 — WhatsApp Outreach Summary drill-through. ?channel=whatsapp
        // narrows to the board population (contacts with a WhatsApp send), and
        // ?outreach_state applies the SAME state condition the board counts by
        // (Contact::outreachStateSql via the scope), so count == drilled length.
        if ($request->query('channel') === 'whatsapp') {
            $query->hasWhatsappOutreach();
        }
        $outreachState = (string) $request->query('outreach_state', '');
        if ($outreachState !== '' && in_array($outreachState, Contact::OUTREACH_BOARD_STATES_ALL, true)) {
            $query->outreachState($outreachState);
        }

        if ($request->filled('search')) {
            // AT-131 — canonical contact search: matches name + id_number + ALL
            // identifiers (child phones/emails, not just the mirror), relevance-
            // ordered + newest-first. Closes the AT-125 secondary-identifier gap.
            $query->search($request->search);
        }

        if ($request->filled('type')) {
            // Buyer/seller truth is NOT in contact_type_id (a nullable, mostly-
            // unpopulated classification). Buyer = is_buyer; seller = a
            // contact_property pivot with role 'owner'. Resolve the submitted
            // contact_type to its esign_role (dynamic — ids differ per env) and
            // query the canonical column. Genuine classifications (Witness, etc.)
            // keep the contact_type_id filter.
            $typeId = (int) $request->type;
            $esignRole = ContactType::whereKey($typeId)->value('esign_role');

            if ($esignRole === 'buyer') {
                $query->where('is_buyer', 1);
            } elseif ($esignRole === 'seller') {
                $query->whereHas('properties', fn ($q) => $q->where('contact_property.role', 'owner'));
            } else {
                $query->where('contact_type_id', $typeId);
            }
        }

        // Page size is agency-configurable (Settings → Contacts). Clamp the
        // stored value to a sane range so a missing/invalid value can't break paging.
        $perPage = (int) PerformanceSetting::get('contacts_per_page', 25);
        $perPage = $perPage > 0 ? min($perPage, 200) : 25;
        // Eager-load picker relations so the inline edit-row pickers don't N+1.
        $contacts     = $query->with(['tags', 'parentTypes'])->paginate($perPage)->withQueryString();
        // The four fixed parents, each with its agency-scoped sub-tags — feeds
        // the type/tag pop-up picker on the contact forms (AT-79).
        $contactTypes = ContactType::parents()->with('subTags')->get()->unique('name')->values();

        $agentList     = $canPickAgent ? $this->agentList()->values() : collect();
        $selectedAgent = ($canPickAgent && $filterAgentId !== '')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

        return view('corex.contacts.index', compact(
            'contacts', 'contactTypes', 'filterAgentId', 'agentList', 'selectedAgent', 'canPickAgent'
        ));
    }

    /**
     * AT-273 — Street & Complex Search results page.
     *
     * An address-only search (Contact::scopeStreetComplexSearch) that renders a
     * dedicated report of matching contacts, each tagged with Last Contacted,
     * Last Modified and its Linked-Property status. The same result set is
     * downloadable as a PDF via streetComplexSearchPdf(). Both honour the
     * caller's contact data-scope (own / branch / all) exactly like index().
     */
    /**
     * The Street & Complex Search sort options — key => human label. The label is
     * shown in the sort dropdown and echoed onto the PDF header. Keys are the ONLY
     * accepted `sort` values (anything else falls back to 'name').
     */
    public const STREET_COMPLEX_SORTS = [
        'name'           => 'Contact name',
        'unit'           => 'Unit number',
        'complex'        => 'Complex name',
        'street'         => 'Street name',
        'suburb'         => 'Suburb',
        'last_contacted' => 'Last contacted',
        'last_modified'  => 'Last modified',
        'linked'         => 'Linked properties',
    ];

    public function streetComplexSearch(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();

        $filterAgentId = '';
        $canPickAgent  = false;
        $query = $this->scopedContactBaseQuery($request, $user, $filterAgentId, $canPickAgent);

        $term     = trim((string) $request->query('q', ''));
        $cap      = 500;
        $contacts = collect();
        $total    = 0;
        $capped   = false;

        [$sort, $dir] = $this->resolveStreetComplexSort($request);

        if ($term !== '') {
            $query->streetComplexSearch($term)
                  ->with(['agent', 'createdBy', 'type', 'tags', 'properties'])
                  ->withCount('properties');
            $this->applyStreetComplexSort($query, $sort, $dir);
            $total    = (clone $query)->count();
            $contacts = $query->limit($cap)->get();
            $capped   = $total > $cap;
        }

        $sortOptions = self::STREET_COMPLEX_SORTS;

        return view('corex.contacts.street-complex-search', compact(
            'contacts', 'term', 'cap', 'total', 'capped', 'filterAgentId', 'canPickAgent',
            'sort', 'dir', 'sortOptions'
        ));
    }

    /**
     * AT-273 — the same Street & Complex Search result set as a downloadable PDF
     * (dompdf). Mirrors the query in streetComplexSearch() exactly so the report
     * on screen and the report on paper are identical.
     */
    public function streetComplexSearchPdf(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();

        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return redirect()->route('corex.contacts.street-complex-search');
        }

        $filterAgentId = '';
        $canPickAgent  = false;
        $query = $this->scopedContactBaseQuery($request, $user, $filterAgentId, $canPickAgent);

        $cap = 500;
        [$sort, $dir] = $this->resolveStreetComplexSort($request);
        $query->streetComplexSearch($term)
              ->with(['agent', 'createdBy', 'type', 'properties'])
              ->withCount('properties');
        $this->applyStreetComplexSort($query, $sort, $dir);
        $total    = (clone $query)->count();
        $contacts = $query->limit($cap)->get();
        $capped   = $total > $cap;

        $agency      = \App\Models\Agency::find($user->effectiveAgencyId());
        $generatedAt = now();
        $sortLabel   = self::STREET_COMPLEX_SORTS[$sort] . ' (' . ($dir === 'desc' ? 'Z–A / newest' : 'A–Z / oldest') . ')';

        $pdf = Pdf::loadView('corex.contacts.street-complex-search-pdf', compact(
            'contacts', 'term', 'cap', 'total', 'capped', 'agency', 'generatedAt', 'sortLabel'
        ) + ['generatedBy' => $user])->setPaper('a4', 'portrait');

        // Embedded content only — no network fetches from within the renderer.
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('dpi', 96);

        // dompdf must write its font-metrics cache; the default storage/fonts is
        // owned by the deploy user and not writable by php-fpm on the servers.
        // Point it at a runtime-created, web-writable dir (same fix as the
        // property brochure PDF).
        $fontDir = storage_path('app/dompdf-fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        if (is_dir($fontDir) && is_writable($fontDir)) {
            $pdf->setOption('fontDir', $fontDir);
            $pdf->setOption('fontCache', $fontDir);
        }

        $slug = Str::slug($term) ?: 'search';

        return $pdf->download('Street-Complex-Search-' . $slug . '.pdf');
    }

    /**
     * The contacts base query with the caller's data-scope applied — the same
     * agent-scope narrowing index() performs (own / branch / all + explicit
     * ?agent_id). Extracted so the Street & Complex Search page and its PDF
     * scope identically. Sets $filterAgentId / $canPickAgent by reference for
     * the caller to echo back into the view.
     */
    private function scopedContactBaseQuery(Request $request, User $user, string &$filterAgentId, bool &$canPickAgent)
    {
        $dataScope    = PermissionService::getDataScope($user, 'contacts');
        $canPickAgent = in_array($dataScope, ['all', 'branch']);

        // AT-273 — the Street & Complex (property) search ALWAYS runs at the
        // caller's FULL contact-visibility breadth: 'all' = the whole agency book,
        // 'branch' = the caller's branch, 'own' = their own contacts. Visibility is
        // governed purely by the agency's data-scope config (ContactScope enforces
        // it globally; the branch clause below supplements it exactly as the list's
        // "All Contacts" browse does).
        //
        // It deliberately does NOT inherit the contacts-list "My Contacts" per-agent
        // default. Property matches are almost always owned by OTHER agents, so a
        // property search that silently narrowed to the caller's own contacts
        // returned ~0 results whenever the user hadn't first flipped the list to
        // "All Contacts" — the exact bug this closes. filterAgentId is therefore
        // pinned to '' (full scope) and any inherited ?agent_id is ignored.
        $filterAgentId = '';

        $query = Contact::query();

        if ($canPickAgent) {
            // 'branch' scope needs an explicit branch narrowing (mirrors the list's
            // "All Contacts" path); 'all' scope = full agency book (ContactScope
            // leaves it unrestricted).
            if ($dataScope === 'branch' && $user->branch_id) {
                $query->whereHas('createdBy', fn ($q) => $q->where('branch_id', $user->branch_id));
            }
        } else {
            // 'own' scope: agents see only their own (ContactScope also enforces this).
            $query->where('created_by_user_id', $user->id);
        }

        return $query;
    }

    /**
     * Resolve the requested Street & Complex Search sort into a validated
     * [key, direction] pair. Unknown keys fall back to 'name'. When no direction
     * is supplied the default is per-field: date/linked sorts default to DESC
     * (most recent / linked first), everything else to ASC (A–Z / 0–9).
     */
    private function resolveStreetComplexSort(Request $request): array
    {
        $sort = (string) $request->query('sort', 'name');
        if (! array_key_exists($sort, self::STREET_COMPLEX_SORTS)) {
            $sort = 'name';
        }

        $dir = strtolower((string) $request->query('dir', ''));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = in_array($sort, ['last_contacted', 'last_modified', 'linked'], true) ? 'desc' : 'asc';
        }

        return [$sort, $dir];
    }

    /**
     * Apply the chosen sort to a Street & Complex Search query. Sorts on the
     * CONTACT's own columns (its captured structured address + the date tags),
     * so it works whether the match came from the contact's address or a linked
     * property. Blanks/nulls always sort last regardless of direction; a final
     * id tiebreak keeps paging/limits stable. Requires withCount('properties')
     * for the 'linked' sort.
     */
    private function applyStreetComplexSort($query, string $sort, string $dir)
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        switch ($sort) {
            case 'unit':
                // Numeric-aware: "17" before "100"; "3A" sorts by its leading 17→3.
                $query->orderByRaw("(unit_number IS NULL OR unit_number = '')")
                      ->orderByRaw("CAST(unit_number AS UNSIGNED) $dir")
                      ->orderBy('unit_number', $dir);
                break;
            case 'complex':
                $query->orderByRaw("(complex_name IS NULL OR complex_name = '')")
                      ->orderBy('complex_name', $dir);
                break;
            case 'street':
                $query->orderByRaw("(street_name IS NULL OR street_name = '')")
                      ->orderBy('street_name', $dir);
                break;
            case 'suburb':
                $query->orderByRaw("(suburb IS NULL OR suburb = '')")
                      ->orderBy('suburb', $dir);
                break;
            case 'last_contacted':
                $query->orderByRaw('last_contacted_at IS NULL')
                      ->orderBy('last_contacted_at', $dir);
                break;
            case 'last_modified':
                $query->orderByRaw('COALESCE(modified_at, updated_at) IS NULL')
                      ->orderByRaw('COALESCE(modified_at, updated_at) ' . $dir);
                break;
            case 'linked':
                $query->orderBy('properties_count', $dir);
                break;
            case 'name':
            default:
                $query->orderBy('last_name', $dir)->orderBy('first_name', $dir);
                break;
        }

        return $query->orderBy('id');
    }

    public function show(Request $request, Contact $contact)
    {
        // JSON response for prefill / AJAX
        if ($request->wantsJson()) {
            return response()->json([
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'is_buyer' => $contact->is_buyer,
            ]);
        }

        $contact->load(['type', 'parentTypes', 'createdBy', 'agent', 'secondAgent', 'contactNotes.user', 'testimonials.user', 'testimonials.agent', 'documents.uploader', 'documents.documentType', 'documents.properties', 'properties', 'matches.createdBy', 'tags', 'communications', 'phones', 'emails']);

        // Agents in this contact's agency — for the "agent this testimonial is
        // about" selector on the Notes & Testimonials tab.
        $agencyAgents = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $contactTypes     = ContactType::parents()->with('subTags')->get()->unique('name')->values();
        $contactTags      = ContactTag::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $matchCategories  = PropertySettingItem::group('category')->get();
        $matchTypes       = PropertySettingItem::group('property_type')->where('active', true)->get();
        $documentTypes    = DocumentType::active()->ordered()->get();

        // Group documents by property for the Drive tab
        $allDocs = $contact->documents;
        $driveLinkedGroups = [];
        $driveUnlinkedDocs = collect();
        foreach ($allDocs as $doc) {
            $propId = $doc->properties->first()?->id;
            if ($propId) {
                $driveLinkedGroups[$propId][] = $doc;
            } else {
                $driveUnlinkedDocs->push($doc);
            }
        }
        $drivePropertyMap = $contact->properties->keyBy('id');

        // Viewings & Feedback — buyer perspective: every event where this
        // contact appears as a Contact-typed link, regardless of pivot.role.
        // CAL-7 Class 3 — previously this whitelisted role IN [buyer_contact,
        // attendee]. On staging's live-copy DB (and any host where CAL-7
        // Class 1's null-config path saves links with a different role),
        // valid contact links with role='seller_contact' or NULL were
        // silently dropped — surface-level symptom: "captured feedback on a
        // viewing, the contact page shows nothing." The linkable_type
        // predicate already restricts to Contact rows; the role filter
        // was duplicative + harmful.
        $buyerViewings = collect();
        $buyerEventIds = \DB::table('calendar_event_links')
            ->where('linkable_type', \App\Models\Contact::class)
            ->where('linkable_id', $contact->id)
            ->pluck('calendar_event_id');

        if ($buyerEventIds->isNotEmpty()) {
            $propLinks = \DB::table('calendar_event_links')
                ->whereIn('calendar_event_id', $buyerEventIds)
                ->where('role', 'subject_property')
                ->where('linkable_type', \App\Models\Property::class)
                ->get(['calendar_event_id', 'linkable_id']);

            $events = \App\Models\CommandCenter\CalendarEvent::withoutGlobalScopes()
                ->whereIn('id', $buyerEventIds)->get()->keyBy('id');
            $props = \App\Models\Property::withoutGlobalScopes()
                ->whereIn('id', $propLinks->pluck('linkable_id')->unique())->get()->keyBy('id');
            $feedbackRows = \DB::table('calendar_event_feedback')
                ->where('contact_id', $contact->id)
                ->whereIn('calendar_event_id', $buyerEventIds)->get()->groupBy('calendar_event_id');
            $agents = \App\Models\User::withoutGlobalScopes()
                ->whereIn('id', $events->pluck('user_id')->unique()->filter())->pluck('name', 'id');
            $outcomeLabels = \DB::table('agency_feedback_options')->where('category', 'outcome')->pluck('label', 'id');

            foreach ($propLinks as $pl) {
                $ev = $events->get($pl->calendar_event_id);
                $pr = $props->get($pl->linkable_id);
                if (!$ev || !$pr) continue;
                $fb = ($feedbackRows->get($pl->calendar_event_id, collect()))->firstWhere('property_id', $pl->linkable_id)
                    ?? ($feedbackRows->get($pl->calendar_event_id, collect()))->first();
                $buyerViewings->push([
                    'property_id' => $pr->id,
                    'address' => method_exists($pr, 'buildDisplayAddress') ? $pr->buildDisplayAddress() : ($pr->title ?? "Property #{$pr->id}"),
                    'event_date' => $ev->event_date,
                    'agent_name' => $agents->get($ev->user_id, 'Unknown'),
                    'feedback' => $fb ? [
                        'outcome_label' => $outcomeLabels->get($fb->outcome_option_id),
                        'seller_notes' => $fb->seller_visible_notes,
                        'internal_notes' => $fb->internal_notes,
                        'captured_at' => $fb->captured_at,
                    ] : null,
                ]);
            }
            $buyerViewings = $buyerViewings->sortByDesc('event_date')->values();
        }

        // Seller perspective: every property this contact is linked to in the
        // contact_property pivot, regardless of role. CAL-7 Class 3 — the
        // ['owner','seller','landlord','lessor'] whitelist matched the
        // pre-CAL-4 propertyOwners whitelist and dropped pivot rows with
        // NULL or any other role. The same scale-dependent staging bug
        // CAL-4 fixed for the create-event auto-fill applied here on the
        // contact-page read side. Surface every linked property; the
        // section header reads "Seller perspective" but a contact linked
        // to a property via ANY role is effectively a stakeholder in that
        // property's viewing feedback.
        $sellerViewings = collect();
        $ownedPropertyIds = \DB::table('contact_property')
            ->where('contact_id', $contact->id)
            ->pluck('property_id');

        if ($ownedPropertyIds->isNotEmpty()) {
            $sellerEventIds = \DB::table('calendar_event_links')
                ->where('linkable_type', \App\Models\Property::class)
                ->whereIn('linkable_id', $ownedPropertyIds)
                ->where('role', 'subject_property')
                ->pluck('calendar_event_id')->unique();

            if ($sellerEventIds->isNotEmpty()) {
                $sEvents = \App\Models\CommandCenter\CalendarEvent::withoutGlobalScopes()
                    ->whereIn('id', $sellerEventIds)->get()->keyBy('id');
                $sProps = \App\Models\Property::withoutGlobalScopes()
                    ->whereIn('id', $ownedPropertyIds)->get()->keyBy('id');
                // Filter internal_only feedback: only BM/admin/super_admin can see
                $viewerCanSeeInternal = in_array($request->user()->role ?? 'agent', ['super_admin', 'admin', 'owner', 'branch_manager']);
                $sFeedbackQuery = \DB::table('calendar_event_feedback')
                    ->whereIn('calendar_event_id', $sellerEventIds);
                if (!$viewerCanSeeInternal) {
                    $sFeedbackQuery->where('visibility', '!=', 'internal_only');
                }
                $sFeedback = $sFeedbackQuery->get()->groupBy('calendar_event_id');
                $sAgents = \App\Models\User::withoutGlobalScopes()
                    ->whereIn('id', $sEvents->pluck('user_id')->unique()->filter())->pluck('name', 'id');
                $sOutcomes = \DB::table('agency_feedback_options')->where('category', 'outcome')->pluck('label', 'id');

                $sPropLinks = \DB::table('calendar_event_links')
                    ->whereIn('calendar_event_id', $sellerEventIds)
                    ->where('role', 'subject_property')
                    ->whereIn('linkable_id', $ownedPropertyIds)
                    ->get(['calendar_event_id', 'linkable_id']);

                foreach ($sPropLinks as $sl) {
                    $sEv = $sEvents->get($sl->calendar_event_id);
                    $sPr = $sProps->get($sl->linkable_id);
                    if (!$sEv || !$sPr) continue;
                    $sFb = ($sFeedback->get($sl->calendar_event_id, collect()))->first();
                    $sellerViewings->push([
                        'property_id' => $sPr->id,
                        'address' => method_exists($sPr, 'buildDisplayAddress') ? $sPr->buildDisplayAddress() : ($sPr->title ?? "Property #{$sPr->id}"),
                        'event_date' => $sEv->event_date,
                        'agent_name' => $sAgents->get($sEv->user_id, 'Unknown'),
                        'buyer_label' => 'Interested Buyer',
                        'feedback' => $sFb ? [
                            'outcome_label' => $sOutcomes->get($sFb->outcome_option_id),
                            'seller_notes' => $sFb->seller_visible_notes,
                            'captured_at' => $sFb->captured_at,
                        ] : null,
                    ]);
                }
                $sellerViewings = $sellerViewings->sortByDesc('event_date')->values();
            }
        }

        $now = now();
        $buyerUpcoming = $buyerViewings->filter(fn ($v) => \Carbon\Carbon::parse($v['event_date'])->gte($now))->sortBy('event_date')->values();
        $buyerPast = $buyerViewings->filter(fn ($v) => \Carbon\Carbon::parse($v['event_date'])->lt($now))->sortByDesc('event_date')->values();
        $sellerUpcoming = $sellerViewings->filter(fn ($v) => \Carbon\Carbon::parse($v['event_date'])->gte($now))->sortBy('event_date')->values();
        $sellerPast = $sellerViewings->filter(fn ($v) => \Carbon\Carbon::parse($v['event_date'])->lt($now))->sortByDesc('event_date')->values();
        $viewingsCount = $buyerViewings->count() + $sellerViewings->count();

        $featureOptions = \App\Http\Controllers\CoreX\ContactMatchController::FEATURE_OPTIONS;

        // Seller-outreach timeline (Prompt 07). Only fetched when the viewer
        // has the composer permission — gated tab.
        $outreachSends = collect();
        $outreachClickCounts = collect();
        $outreachOutcomeOptions = [];
        if ($request->user()->hasPermission('outreach.compose')) {
            $agencyId = $request->user()?->effectiveAgencyId();
            if ($agencyId !== null && (int) $contact->agency_id === (int) $agencyId) {
                $timeline = app(\App\Http\Controllers\SellerOutreach\ContactTimelineController::class)
                    ->buildTimelineData((int) $agencyId, $contact);
                $outreachSends = $timeline['sends'];
                $outreachClickCounts = $timeline['clickCounts'];
                $outreachOutcomeOptions = $timeline['outcomeOptions'];
            }
        }

        // AT-118 — Communications Access Gate. The old binary
        // access_communication_archive capability (the agency-wide compliance
        // archive) is REPLACED here by a layered, server-side, per-contact gate.
        // A user may see THIS contact's threads (email + WhatsApp) iff:
        //   • they hold communications.grant_access (an authoriser must see the
        //     threads in order to authorise a request), OR
        //   • they hold an active session-scoped grant for this contact
        //     (Flow A — CommsAccessGrantService::hasActiveGrant, session + midnight bound), OR
        //   • their communications.view scope (own/branch/all) covers ≥1 thread —
        //     where "own" === the owning agent (communications.owner_user_id).
        // The rows are filtered server-side via Communication::scopeVisibleTo, so a
        // user without visibility never receives the data (not merely hidden in UI).
        // NULL-owner rows (legacy/outbound provisional) never open under own/branch
        // — only 'all'. Routine views are NOT logged to comms_access_audit_log: that
        // sink is reserved for access-CONTROL events (request/grant/decline/transfer,
        // Steps 3-4); ordinary contact views are already captured by contact_access_log.
        $viewer       = auth()->user();
        $scope        = $viewer ? PermissionService::getDataScope($viewer, 'communications') : null;
        $isAuthoriser = (bool) $viewer?->hasPermission('communications.grant_access');
        $hasGrant     = $viewer
            ? app(\App\Services\Communications\CommsAccessGrantService::class)->hasActiveGrant($viewer, $contact)
            : false;

        // AT-132 Step 4 — ALL of this contact's threads (safe metadata for the list).
        // The list itself is always shown to a comms-capable user; only the BODIES
        // are gated. Visibility per comm is decided by the ONE source of truth
        // (CommsAccessGrantService::applyArchiveVisibility — Step 3), so the contact
        // tab and the compliance archive can never drift.
        $grantService  = app(\App\Services\Communications\CommsAccessGrantService::class);
        $allContactComms = \App\Models\Communications\Communication::query()
            ->whereNull('purged_at')
            ->with('owner:id,name')
            ->whereHas('links', function ($q) use ($contact) {
                $q->where('linkable_type', \App\Models\Contact::class)
                  ->where('linkable_id', $contact->id);
            })
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();

        // AT-153 — resolve owning-agent NAMES without AgencyScope so a null-agency
        // (platform-owned) or other-agency owner still resolves to a name for the
        // gated thread row. The eager-loaded `owner` relation is AgencyScope-filtered
        // and returns NULL for such owners (→ "Unassigned"); this map is NAME-ONLY
        // (no bodies, no identifiers) and drives "Private to {agent} — request access".
        $ownerUserIds = $allContactComms->pluck('owner_user_id')->filter()->unique()->all();
        $ownerNameMap = $ownerUserIds
            ? \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->whereIn('id', $ownerUserIds)
                ->pluck('name', 'id')
                ->all()
            : [];

        $allIds = $allContactComms->pluck('id')->all();
        $visibleIds = $allIds
            ? $grantService->applyArchiveVisibility(
                  \App\Models\Communications\Communication::query()->whereIn('id', $allIds),
                  $viewer
              )->pluck('id')->map(fn ($i) => (int) $i)->all()
            : [];
        $visibleSet = array_flip($visibleIds);

        // Existing refs (badge / documents-tab "in archive" link) keep meaning the
        // set of comms whose BODIES this user may see.
        $contactComms = $allContactComms->whereIn('id', $visibleIds)->values();
        $canViewComms = $contactComms->isNotEmpty();

        // Per-thread hide-subject (owner privacy control, Step 1) + this user's
        // still-pending per-thread requests (so a row can render its "requested" state).
        $threadSettings = \App\Models\Communications\CommsThreadSetting::forContact($contact->id)
            ->get()->keyBy('thread_key');
        $pendingReqs = \App\Models\Communications\CommsAccessRequest::byRequester($viewer->id)
            ->forContact($contact->id)->pending()->where('expires_at', '>', now())->get();
        $pendingThreadKeys = $pendingReqs->whereNotNull('thread_key')->pluck('thread_key')->all();
        $pendingCommIds    = $pendingReqs->whereNull('thread_key')->pluck('communication_id')
            ->filter()->map(fn ($i) => (int) $i)->all();

        // AT-132 Step 6/7 — this viewer's OWN live grants on this contact, so a
        // granted row can show its mode + a "Revoke access" control (No Silent Locks
        // + full-CRUD floor: the grant a viewer holds is removable from the surface).
        $viewerGrants = \App\Models\Communications\CommsAccessRequest::byRequester($viewer->id)
            ->forContact($contact->id)->liveGrant()->get();
        $grantByThread = $viewerGrants->whereNotNull('thread_key')->keyBy('thread_key');
        $grantByComm   = $viewerGrants->whereNull('thread_key')->whereNotNull('communication_id')->keyBy('communication_id');

        // Group comms into threads: real thread_key → one row; NULL/empty thread_key
        // → each comm its own row keyed on communication_id (never grouped — AT-132 §2).
        $grouped = [];
        foreach ($allContactComms as $c) {
            $tk  = ($c->thread_key !== null && $c->thread_key !== '') ? $c->thread_key : null;
            $key = $tk !== null ? 'tk:' . $tk : 'comm:' . $c->id;
            $grouped[$key][] = $c;
        }

        $contactThreads = collect();
        foreach ($grouped as $key => $msgs) {
            $latest      = $msgs[0]; // query is occurred_at DESC → first is newest
            $isNull      = str_starts_with($key, 'comm:');
            $tk          = $isNull ? null : $latest->thread_key;
            $hideSubject = ($tk !== null && isset($threadSettings[$tk])) ? (bool) $threadSettings[$tk]->hide_subject : false;

            $subject = null;
            foreach ($msgs as $m) {
                if (trim((string) $m->subject) !== '') { $subject = $m->subject; break; }
            }

            $visible = false;
            foreach ($msgs as $m) { if (isset($visibleSet[$m->id])) { $visible = true; break; } }

            $pending = $isNull
                ? in_array((int) $latest->id, $pendingCommIds, true)
                : in_array($tk, $pendingThreadKeys, true);

            // The owner of the thread (or a grant_access holder) may toggle the
            // hide-subject control. Only meaningful for real threads (the settings
            // table keys on a non-null thread_key).
            $ownerId        = $latest->owner_user_id;
            $canManageSubj  = $tk !== null && ($isAuthoriser || ($viewer && (int) $ownerId === (int) $viewer->id));

            // This viewer's own live grant for this thread/comm (if any) → revocable.
            $ownGrant = $tk !== null ? ($grantByThread[$tk] ?? null) : ($grantByComm[(int) $latest->id] ?? null);

            $contactThreads->push((object) [
                'row_key'            => $key,
                'thread_key'         => $tk,
                'communication_id'   => $isNull ? (int) $latest->id : null,
                'channel'            => $latest->channel,
                'latest_at'          => $latest->occurred_at,
                'message_count'      => count($msgs),
                // AT-153 — name resolved unscoped (see $ownerNameMap); falls back to
                // the scoped relation, then null → the row renders "Unassigned".
                'owner_name'         => ($ownerId ? ($ownerNameMap[$ownerId] ?? null) : null) ?: $latest->owner?->name,
                'has_attachments'    => collect($msgs)->contains(fn ($m) => (bool) $m->has_attachments),
                // hide_subject protects the subject from viewers who CAN'T read the
                // thread (the gated list). A viewer who can see the body still sees
                // the subject. subject_hidden = effective-for-this-row; the raw
                // setting drives the owner's toggle state + "hidden from others" note.
                'subject'                => ($hideSubject && !$visible) ? null : $subject,
                'subject_hidden'         => ($hideSubject && !$visible),
                'subject_hidden_setting' => $hideSubject,
                'is_visible'             => $visible,
                'pending'                => $pending,
                'can_manage_subject'     => $canManageSubj,
                // viewer's own revocable grant (null when access is via ownership/
                // scope/participant/legacy rather than a per-thread grant they hold).
                'viewer_grant_id'        => $ownGrant?->id,
                'viewer_grant_mode'      => $ownGrant?->grant_mode,
            ]);
        }

        // The comms tab + its thread list show for any comms-capable user (the
        // metadata is safe); bodies stay gated per row. A user with no comms
        // capability but a live grant (rare) still sees it because they can view ≥1.
        $commsTabVisible = $isAuthoriser || $scope !== null || $canViewComms;

        // Kept for blade compatibility: $canRequestComms now means "the comms tab is
        // available to this user" (the per-row Request buttons drive the real flow).
        $commsViaGrant   = $hasGrant;
        $canRequestComms = $commsTabVisible;
        $pendingCommsRequest = $pendingReqs->first();

        // AT-59 — tile counts DERIVE from the communications archive (outbound,
        // provisional + confirmed), not the legacy scalar columns. The relation
        // is eager-loaded above so these are computed in memory (no N+1).
        $waSent    = $contact->outboundCommCount(\App\Models\Communications\Communication::CHANNEL_WHATSAPP);
        $emailSent = $contact->outboundCommCount(\App\Models\Communications\Communication::CHANNEL_EMAIL);

        // AT-136 — the viewing agent's WhatsApp-capture decision for THIS contact
        // (per-agent; SEPARATE from AT-125 marketing opt-out). null = no WA match yet.
        $myCaptureStatus = $viewer
            ? optional(\App\Models\Communications\AgentCaptureConsent::query()
                ->where('agent_user_id', $viewer->id)->where('contact_id', $contact->id)->first())->status
            : null;

        return view('corex.contacts.show', compact('contact', 'contactTypes', 'contactTags', 'matchCategories', 'matchTypes', 'featureOptions', 'documentTypes', 'driveLinkedGroups', 'driveUnlinkedDocs', 'drivePropertyMap', 'buyerViewings', 'sellerViewings', 'buyerUpcoming', 'buyerPast', 'sellerUpcoming', 'sellerPast', 'viewingsCount', 'outreachSends', 'outreachClickCounts', 'outreachOutcomeOptions', 'agencyAgents', 'canViewComms', 'contactComms', 'contactThreads', 'commsViaGrant', 'canRequestComms', 'pendingCommsRequest', 'myCaptureStatus', 'waSent', 'emailSent'));
    }

    public function checkDuplicate(Request $request)
    {
        $request->validate([
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
        ]);

        $phone = $request->input('phone');
        $email = $request->input('email');

        if (!$phone && !$email) {
            return response()->json(['found' => false]);
        }

        // AT-125 — route the pre-check through the canonical multi-identifier
        // service so it matches ANY of a contact's phones/emails (child tables),
        // consistent with the authoritative store() check. Agency-scoped; the
        // service drops AgencyScope and filters agency_id explicitly.
        $agencyId = (int) (auth()->user()?->effectiveAgencyId() ?: 0);   // AT-253 Rule 17
        $duplicate = app(ContactDuplicateService::class)
            ->findDuplicatesForIdentifiers(
                $phone ? [$phone] : [],
                $email ? [$email] : [],
                null,
                $agencyId
            )
            ->load(['createdBy', 'agent'])
            ->first();

        if (!$duplicate) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found'          => true,
            'name'           => $duplicate->full_name,
            'phone'          => $duplicate->phone,
            'email'          => $duplicate->email ?? '—',
            'type'           => optional($duplicate->type)->name ?? '—',
            // The agent this contact sits under — primary agent, falling back to
            // the original capturer for contacts predating agent assignment.
            'agent'          => optional($duplicate->agent ?? $duplicate->createdBy)->name ?? 'Unknown',
            'last_contacted' => $duplicate->last_contacted_at
                ? \Carbon\Carbon::parse($duplicate->last_contacted_at)->format('d M Y')
                : 'Never',
            'url'            => route('corex.contacts.show', $duplicate),
        ]);
    }

    /**
     * AT-125 — normalise the repeatable phones[]/emails[] form input into a clean
     * list of {value,label,is_primary}, falling back to the legacy single field.
     * Guarantees exactly one is_primary when any rows exist (first wins).
     *
     * @return array{0:array<int,array{value:string,label:?string,is_primary:bool}>,1:array<int,array{value:string,label:?string,is_primary:bool}>}
     */
    private function extractIdentifiers(Request $request, array $data): array
    {
        $phones = $this->normaliseIdentifierInput($request->input('phones', []));
        $emails = $this->normaliseIdentifierInput($request->input('emails', []));

        if ($phones === [] && !empty($data['phone'])) {
            $phones = [['value' => trim((string) $data['phone']), 'label' => null, 'is_primary' => true]];
        }
        if ($emails === [] && !empty($data['email'])) {
            $emails = [['value' => trim((string) $data['email']), 'label' => null, 'is_primary' => true]];
        }

        return [$phones, $emails];
    }

    /** @return array<int,array{value:string,label:?string,is_primary:bool}> */
    private function normaliseIdentifierInput($input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $row) {
            $value = is_array($row) ? trim((string) ($row['value'] ?? '')) : trim((string) $row);
            if ($value === '') {
                continue;
            }
            $label = is_array($row) ? trim((string) ($row['label'] ?? '')) : '';
            $out[] = [
                'value'      => $value,
                'label'      => $label !== '' ? $label : null,
                'is_primary' => is_array($row) && filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        if ($out !== [] && !collect($out)->contains(fn ($r) => $r['is_primary'])) {
            $out[0]['is_primary'] = true;
        }
        return $out;
    }

    private function primaryValue(array $items): ?string
    {
        foreach ($items as $item) {
            if (!empty($item['is_primary'])) {
                return $item['value'];
            }
        }
        return $items[0]['value'] ?? null;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            // AT-125 — single fields kept for back-compat (external posters); the
            // form posts the repeatable phones[]/emails[] arrays below.
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'phones'              => 'nullable|array',
            'phones.*.value'      => 'nullable|string|max:30',
            'phones.*.label'      => 'nullable|string|max:60',
            'phones.*.is_primary' => 'nullable|boolean',
            'emails'              => 'nullable|array',
            'emails.*.value'      => 'nullable|email|max:150',
            'emails.*.label'      => 'nullable|string|max:60',
            'emails.*.is_primary' => 'nullable|boolean',
            // Type/tag assignments arrive via the pop-up picker and are applied
            // after creation (applyTypeAssignments) — not a single column.
            'notes'           => 'nullable|string|max:1000',
            // Optional SA ID number, captured with a POPIA audit trail.
            'id_number'       => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
            // Duplicate bypass fields
            'bypass_duplicate_check' => 'nullable|boolean',
            'override_reason'        => 'nullable|string|max:500',
        ]);

        // AT-125 — a contact needs at least one identifier (phone OR email), but
        // not necessarily a phone (email-only is valid).
        [$phones, $emails] = $this->extractIdentifiers($request, $data);
        if ($phones === [] && $emails === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phones' => 'Add at least one phone number or email address.',
            ]);
        }

        // Pull the ID out before the duplicate check (matches on phone/email/name)
        // and re-attach it with audit fields once we're past the dupe guard.
        $idNumber = !empty($data['id_number']) ? preg_replace('/\s+/', '', (string) $data['id_number']) : null;
        unset($data['id_number']);

        // The mirror columns are written by ContactIdentifierService from the
        // child rows — never set them directly here.
        unset($data['phone'], $data['email'], $data['phones'], $data['emails']);

        $user = auth()->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);   // AT-253 Rule 17
        $service = app(ContactDuplicateService::class);

        // Primary identifier values for the duplicate-match label + modal display.
        $data['phone'] = $this->primaryValue($phones);
        $data['email'] = $this->primaryValue($emails);

        // Skip duplicate check if explicitly bypassed (user already chose "create anyway")
        if (empty($data['bypass_duplicate_check'])) {
            $duplicates = $service->findDuplicatesForIdentifiers(
                array_column($phones, 'value'),
                array_column($emails, 'value'),
                $idNumber,
                $agencyId
            );

            if ($duplicates->isNotEmpty()) {
                $mode = $service->resolveMode($agencyId);
                $match = $service->identifyMatch($data, $duplicates->first(), $agencyId);

                // auto_link: silently redirect to existing contact
                if ($mode === 'auto_link') {
                    $existing = $duplicates->first();
                    $service->logAttempt(
                        $agencyId, $user->id, $mode,
                        $match['field'], $match['value'],
                        $existing->id, $data, 'auto_linked'
                    );
                    // The match runs agency-wide (ContactScope is bypassed), so the
                    // existing contact may sit outside this user's visibility. Only
                    // redirect to it when they can actually open it — otherwise the
                    // show route 404s. When invisible, fall through to the warn UI.
                    if (Contact::whereKey($existing->id)->exists()) {
                        return redirect()->route('corex.contacts.show', $existing)
                            ->with('info', 'Existing contact found and linked automatically.');
                    }
                }

                // The duplicate search bypasses ContactScope (it must catch
                // agency-wide dupes), so a match may be owned by another agent /
                // branch and be invisible to this user. Mark which ones they can
                // actually open: the modal only offers "Use Existing" + contact
                // details for viewable matches, and never links to a record the
                // show route would 404 on.
                $viewableIds = Contact::whereIn('id', $duplicates->pluck('id'))->pluck('id')->all();
                $mapDuplicate = function ($c) use ($mode, $viewableIds) {
                    $canView = in_array($c->id, $viewableIds, true);
                    $hide    = $mode === 'hard_block_request' || ! $canView;
                    return [
                        'id'       => $c->id,
                        'name'     => $c->full_name,
                        'phone'    => $hide ? null : $c->phone,
                        'email'    => $hide ? null : $c->email,
                        'owner'    => optional($c->createdBy)->name ?? 'Unknown',
                        'can_view' => $canView,
                        'url'      => $canView ? route('corex.contacts.show', $c) : null,
                    ];
                };

                // Return 422 with duplicates for modal display
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'duplicates' => $duplicates->map($mapDuplicate),
                        'mode' => $mode,
                        'match_field' => $match['field'],
                        'can_override' => $mode === 'hard_block_override' && in_array($user->effectiveRole(), ['admin', 'super_admin', 'owner']),
                    ], 422);
                }

                // Non-AJAX fallback: redirect back with duplicate info in session
                return back()->withInput()->with('duplicate_detected', [
                    'duplicates' => $duplicates->map($mapDuplicate)->toArray(),
                    'mode' => $mode,
                    'match_field' => $match['field'],
                    'can_override' => $mode === 'hard_block_override' && in_array($user->effectiveRole(), ['admin', 'super_admin', 'owner']),
                ]);
            }
        } else {
            // Bypassed — log the override
            $mode = $service->resolveMode($agencyId);
            $actionTaken = !empty($data['override_reason']) ? 'override_with_reason' : 'created_anyway';
            $service->logAttempt(
                $agencyId, $user->id, $mode,
                'bypass', '', null, $data, $actionTaken, $data['override_reason'] ?? null
            );
        }

        // Remove bypass fields before creating
        unset($data['bypass_duplicate_check'], $data['override_reason']);
        $data['created_by_user_id'] = $user->id;
        // Primary agent defaults to the creator via ContactObserver::creating()
        // (centralised so every ingress path behaves the same); reassignable from
        // the contact's Info tab.

        // Re-attach the SA ID with its POPIA audit fields.
        if ($idNumber) {
            $data['id_number']             = $idNumber;
            $data['id_number_captured_at'] = now();
            $data['id_number_source']      = 'contact_quick_add';
        }
        $data['branch_id'] = $user->branch_id
            ?? \DB::table('branches')->where('agency_id', $agencyId)->min('id')
            ?? 1;

        // The mirror columns are owned by ContactIdentifierService — strip the
        // primary values used for the dedupe label so Contact::create writes none.
        unset($data['phone'], $data['email']);

        // Wrapped so a failed type-assignment validation (contact type is
        // required) rolls the just-created contact back — no orphan record.
        $contact = \DB::transaction(function () use ($data, $request, $phones, $emails) {
            $contact = Contact::create($data);
            // AT-125 — write the multi-identifier child rows (mirror + one-primary
            // invariant kept correct by the canonical sync point).
            app(\App\Services\Contacts\ContactIdentifierService::class)->syncIdentifiers($contact, $phones, $emails);
            $this->applyTypeAssignments($contact, $request);
            return $contact;
        });

        return redirect()->route('corex.contacts.index')->with('success', 'Contact added successfully.');
    }

    /**
     * Apply the pop-up picker's type/tag selections (AT-79). Creates any
     * inline-new sub-tags under their parent, then delegates to
     * Contact::syncTypeAssignments() which keeps the multi-parent pivot, the
     * sub-tag pivot and the primary-type mirror consistent. Fires ContactTagged
     * for newly attached sub-tags. Shared by store() and update().
     */
    private function applyTypeAssignments(Contact $contact, Request $request): void
    {
        $parentIdsAllowed = ContactType::parentIds();

        $validated = $request->validate([
            'parent_type_ids'      => 'required|array|min:1',
            'parent_type_ids.*'    => ['integer', \Illuminate\Validation\Rule::in($parentIdsAllowed)],
            'tag_ids'              => 'nullable|array',
            'tag_ids.*'            => 'integer|exists:contact_tags,id',
            'new_tags'             => 'nullable|array',
            'new_tags.*.name'      => 'nullable|string|max:100',
            'new_tags.*.parent_id' => ['nullable', 'required_with:new_tags.*.name', \Illuminate\Validation\Rule::in($parentIdsAllowed)],
        ], [
            'parent_type_ids.required' => 'Please assign at least one contact type.',
            'parent_type_ids.min'      => 'Please assign at least one contact type.',
        ]);

        $parentIds = array_map('intval', $validated['parent_type_ids'] ?? []);
        $tagIds    = array_map('intval', $validated['tag_ids'] ?? []);

        // Inline-created sub-tags: reuse an existing same-name tag under the same
        // parent (agency-scoped, case-insensitive) if present, otherwise create.
        foreach ($validated['new_tags'] ?? [] as $nt) {
            $name = trim((string) ($nt['name'] ?? ''));
            if ($name === '' || empty($nt['parent_id'])) {
                continue;
            }
            $parentId = (int) $nt['parent_id'];
            // Case-insensitive match so a re-typed name (any casing/spacing) does
            // not create a duplicate sub-tag.
            $tag = ContactTag::where('contact_type_id', $parentId)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first()
                ?? ContactTag::create([
                    'contact_type_id' => $parentId,
                    'name'            => $name,
                    'color'           => '#6366f1',
                    'sort_order'      => 0,
                    'is_active'       => true,
                ]);
            $tagIds[] = $tag->id;
        }

        $newlyAttached = $contact->syncTypeAssignments($parentIds, $tagIds);

        if (!empty($newlyAttached)) {
            $tagNames = ContactTag::whereIn('id', $newlyAttached)->pluck('name', 'id');
            foreach ($newlyAttached as $tagId) {
                event(new \App\Events\Contact\ContactTagged(
                    contact: $contact,
                    tag: (string) ($tagNames[$tagId] ?? $tagId),
                    actorUserId: auth()->id(),
                ));
            }
        }
    }

    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            // AT-125 — single fields kept for back-compat; the form posts arrays.
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'phones'              => 'nullable|array',
            'phones.*.value'      => 'nullable|string|max:30',
            'phones.*.label'      => 'nullable|string|max:60',
            'phones.*.is_primary' => 'nullable|boolean',
            'emails'              => 'nullable|array',
            'emails.*.value'      => 'nullable|email|max:150',
            'emails.*.label'      => 'nullable|string|max:60',
            'emails.*.is_primary' => 'nullable|boolean',
            // Type/tag assignments handled by applyTypeAssignments (the picker).
            'notes'           => 'nullable|string|max:1000',
            // Agent assignment — primary (reassignable) + optional co-agent.
            // Constrained to active members of this contact's agency so a
            // tampered POST can't assign an out-of-agency user.
            'agent_id'        => [
                'nullable',
                \Illuminate\Validation\Rule::exists('users', 'id')
                    ->where('agency_id', $contact->agency_id)
                    ->where('is_active', true),
            ],
            'second_agent_id' => [
                'nullable',
                'different:agent_id',
                \Illuminate\Validation\Rule::exists('users', 'id')
                    ->where('agency_id', $contact->agency_id)
                    ->where('is_active', true),
            ],
            'birthday'        => 'nullable|date',
            'id_number'       => 'nullable|string|max:20',
            // Residential address — where the contact lives. Free text, set
            // ONLY here. Distinct from the structured property-address capture
            // (updatePropertyAddress), which never writes to this column.
            'address'         => 'nullable|string|max:500',
            'loaded_at'       => 'nullable|date',
            'modified_at'     => 'nullable|date',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_name'   => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_branch_name'    => 'nullable|string|max:255',
            'bank_branch_code'    => 'nullable|string|max:50',
            'bank_account_type'   => 'nullable|string|max:50',
            // Financial position — buyer pre-approval (spec D3).
            'preapproval_amount'      => 'nullable|numeric|min:0',
            'preapproval_expires_at'  => 'nullable|date',
            'preapproval_institution' => 'nullable|string|max:100',
        ]);

        // A co-agent without a primary is meaningless — collapse it.
        if (array_key_exists('agent_id', $data) && empty($data['agent_id'])) {
            $data['second_agent_id'] = null;
        }

        // AT-118 hardening — reassigning the Primary/Co-Agent is a manager action
        // (contacts.reassign_agent), enforced SERVER-SIDE here, not just by hiding
        // the dropdown. A user without the capability may still edit every other
        // field, but any CHANGE to the assignment is refused. (Comms visibility is
        // owner-based, so this is not a comms-access bypass today — but agent
        // assignment must be manager-controlled regardless.)
        $changingPrimary = array_key_exists('agent_id', $data)
            && (int) ($data['agent_id'] ?? 0) !== (int) ($contact->agent_id ?? 0);
        $changingSecond = array_key_exists('second_agent_id', $data)
            && (int) ($data['second_agent_id'] ?? 0) !== (int) ($contact->second_agent_id ?? 0);
        if (($changingPrimary || $changingSecond) && ! $request->user()->hasPermission('contacts.reassign_agent')) {
            abort(403, 'You do not have permission to change the agent assigned to this contact.');
        }

        // AT-125 — only touch identifiers when the request actually carries them
        // (this endpoint also serves partial edits that must not wipe phones/emails).
        $hasIdentifierInput = $request->has('phones') || $request->has('emails')
            || $request->filled('phone') || $request->filled('email');
        [$phones, $emails] = $this->extractIdentifiers($request, $data);
        if ($hasIdentifierInput && $phones === [] && $emails === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phones' => 'A contact needs at least one phone number or email address.',
            ]);
        }
        // Mirror columns are owned by ContactIdentifierService — never set directly.
        unset($data['phone'], $data['email'], $data['phones'], $data['emails']);

        // Transaction-wrapped so a partial failure (e.g. assignment sync) rolls
        // the whole save back cleanly — no half-written record. The picker's
        // type/tag selections are applied via the shared helper, which keeps the
        // multi-parent pivot, sub-tag pivot and primary-type mirror consistent.
        \DB::transaction(function () use ($contact, $data, $request, $hasIdentifierInput, $phones, $emails) {
            $contact->update($data);
            if ($hasIdentifierInput) {
                app(\App\Services\Contacts\ContactIdentifierService::class)->syncIdentifiers($contact, $phones, $emails);
            }
            $this->applyTypeAssignments($contact, $request);
        });

        // Redirect to show page if coming from there, otherwise index
        if ($request->has('_from_show')) {
            return redirect()->route('corex.contacts.show', $contact)->with('success', 'Contact updated.');
        }

        return redirect()->route('corex.contacts.index')->with('success', 'Contact updated.');
    }

    /**
     * AT-60 — save the STRUCTURED PROPERTY-ADDRESS capture (a property-creation
     * aid on the Properties & Core Matches tab). Independent of the contact's
     * residential `address` (Info free-text) — this NEVER writes to it. All
     * components optional; partial addresses allowed.
     */
    public function updatePropertyAddress(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'unit_number'        => 'nullable|string|max:50',
            'floor_number'       => 'nullable|string|max:50',
            'unit_section_block' => 'nullable|string|max:150',
            'complex_name'       => 'nullable|string|max:150',
            'street_number'      => 'nullable|string|max:50',
            'street_name'        => 'nullable|string|max:200',
            'suburb'             => 'nullable|string|max:120',
            'city'               => 'nullable|string|max:120',
            'province'           => 'nullable|string|max:120',
            // P24 ids from the shared picker (fieldPrefix 'contact_addr').
            'contact_addr_province_id' => 'nullable|integer|exists:p24_provinces,id',
            'contact_addr_city_id'     => 'nullable|integer|exists:p24_cities,id',
            'contact_addr_suburb_id'   => 'nullable|integer|exists:p24_suburbs,id',
        ]);

        // Map the picker's prefixed ids onto the contact columns.
        $data['p24_province_id'] = $data['contact_addr_province_id'] ?? null;
        $data['p24_city_id']     = $data['contact_addr_city_id'] ?? null;
        $data['p24_suburb_id']   = $data['contact_addr_suburb_id'] ?? null;
        unset($data['contact_addr_province_id'], $data['contact_addr_city_id'], $data['contact_addr_suburb_id']);

        // Dangling-name guard (BUILD_STANDARD prevent-or-absorb): a P24 location
        // NAME typed but not matched to a record leaves its id empty. Reject it
        // clearly rather than silently storing an unlinkable suburb.
        $danglers = [];
        foreach (['province' => 'p24_province_id', 'city' => 'p24_city_id', 'suburb' => 'p24_suburb_id'] as $name => $idKey) {
            if (filled($data[$name] ?? null) && empty($data[$idKey])) {
                $danglers[$name] = "Pick a {$name} from the Property24 list, or clear it.";
            }
        }
        if (!empty($danglers)) {
            throw \Illuminate\Validation\ValidationException::withMessages($danglers);
        }

        // Normalise empty optional components to NULL rather than '' so the
        // stored shape is consistent with the dedicated clear path below and so
        // hasStructuredAddress()/composeStructuredAddress() (both filled()-based)
        // never see a "blank but present" column. (BUILD_STANDARD §6 — one shape
        // for the empty value, set everywhere.)
        foreach ([
            'unit_number', 'floor_number', 'unit_section_block', 'complex_name',
            'street_number', 'street_name', 'suburb', 'city', 'province',
        ] as $field) {
            if (array_key_exists($field, $data) && trim((string) ($data[$field] ?? '')) === '') {
                $data[$field] = null;
            }
        }

        // Save ONLY the structured property-address columns — never `address`.
        \DB::transaction(fn () => $contact->update($data));

        $redirect = redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Property address saved.')
            ->with('tab', 'properties');

        // Part 3 — "already on our books" safety net (belt-and-braces alongside the
        // live blur check). If HFC already holds this property (stock or captured
        // intel), surface a warning so the agent doesn't canvass an owner we already
        // represent. Gated by the agency warn toggle inside the guard.
        $held = app(\App\Services\Contact\ContactAddressPropertyGuard::class)
            ->findHeldForContact($contact->fresh());
        if ($held) {
            $redirect->with('held_address_warning', [
                'kind'         => $held['kind'],
                'label'        => $held['label'],
                'address'      => $held['address'],
                'property_url' => $held['property_id']
                    ? route('corex.properties.show', $held['property_id'])
                    : null,
                'tracked_url'  => $held['tracked_id']
                    ? route('corex.tracked-properties.show', $held['tracked_id'])
                    : null,
            ]);
        }

        return $redirect;
    }

    /**
     * Part 3 — live "already on our books" check fired from the address-capture
     * modal (mirrors checkDuplicate). Given raw captured address components, returns
     * whether HFC already holds the property (agency stock or captured intelligence)
     * BEFORE the agent commits the capture and goes on to prospect. Read-only — never
     * mints a tracked property (uses the matcher's findExistingMatch). Honours the
     * agency warn toggle + address_match_mode (the guard returns null when off).
     */
    public function checkHeldAddress(Request $request)
    {
        $request->validate([
            'street_number' => 'nullable|string|max:50',
            'street_name'   => 'nullable|string|max:200',
            'unit_number'   => 'nullable|string|max:50',
            'complex_name'  => 'nullable|string|max:150',
            'suburb'        => 'nullable|string|max:120',
            'city'          => 'nullable|string|max:120',
            'province'      => 'nullable|string|max:120',
        ]);

        $user = $request->user();
        $agencyId = (int) ($user?->effectiveAgencyId() ?? $user?->agency_id ?? 0);
        if ($agencyId <= 0) {
            return response()->json(['held' => false]);
        }

        $held = app(\App\Services\Contact\ContactAddressPropertyGuard::class)
            ->findHeldFromComponents($agencyId, $request->only([
                'street_number', 'street_name', 'unit_number', 'complex_name',
                'suburb', 'city', 'province',
            ]));

        if (! $held) {
            return response()->json(['held' => false]);
        }

        return response()->json([
            'held'         => true,
            'kind'         => $held['kind'], // 'stock' | 'captured'
            'label'        => $held['label'],
            'address'      => $held['address'],
            'property_url' => $held['property_id']
                ? route('corex.properties.show', $held['property_id'])
                : null,
            'tracked_url'  => $held['tracked_id']
                ? route('corex.tracked-properties.show', $held['tracked_id'])
                : null,
        ]);
    }

    /**
     * AT-61 follow-up — REMOVE the captured structured property-address (full
     * CRUD: set/edit existed, delete was missing). Nulls ALL twelve AT-60
     * structured columns in one transactional update so the write is all-or-
     * nothing — a partial failure rolls back and leaves the captured address
     * exactly as it was.
     *
     * Consequence (the point of the feature): with every component null,
     * Contact::hasStructuredAddress() returns false, so the AT-61 outreach
     * "address-only" bypass switches OFF — the composer falls back to the
     * "link a property" gate for this contact (ComposerController::show /
     * ::submit both re-check hasStructuredAddress()).
     *
     * Does NOT touch the contact's residential `address` (Info free-text) and
     * does NOT touch any Property the agent already created from this address —
     * that is a separate, real Property with its own contact_property pivot.
     */
    public function clearPropertyAddress(Request $request, Contact $contact)
    {
        $columns = [
            'unit_number', 'floor_number', 'unit_section_block', 'complex_name',
            'street_number', 'street_name', 'suburb', 'city', 'province',
            'p24_province_id', 'p24_city_id', 'p24_suburb_id',
        ];

        \DB::transaction(fn () => $contact->update(array_fill_keys($columns, null)));

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Property address removed.')
            ->with('tab', 'properties');
    }

    public function touch(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'last_contacted_at' => 'required|date',
        ]);

        $contact->update(['last_contacted_at' => $data['last_contacted_at']]);

        return redirect()->route('corex.contacts.show', $contact)->with('success', 'Last contacted date updated.');
    }

    /**
     * Toggle the per-contact birthday reminder opt-in.
     * When on, the contact's birthday surfaces on the agent's calendar and
     * fires an in-app reminder on the day. Off by default — no birthday noise
     * unless the agent explicitly asks for it on this contact.
     */
    public function toggleBirthdayReminder(Request $request, Contact $contact)
    {
        if (! $contact->birthday) {
            return back()->with('error', 'Add a date of birth before setting a birthday reminder.');
        }

        $contact->update(['birthday_reminder' => ! $contact->birthday_reminder]);

        $message = $contact->birthday_reminder
            ? "You'll be reminded about {$contact->full_name}'s birthday."
            : "Birthday reminder removed for {$contact->full_name}.";

        return back()->with('success', $message);
    }

    /**
     * Record an outbound send from the contact comms tile (AT-59).
     *
     * Instead of a blind scalar bump, this creates a PROVISIONAL outbound
     * communication in the archive — instant tile feedback that the later
     * mailbox/WA ingestion reconciles in place (no double count). The returned
     * count is DERIVED from the archive, so the tile always reflects real sends.
     */
    public function incrementChannel(Request $request, Contact $contact, \App\Services\Communications\OutboundProvisionalLogger $logger, \App\Services\Outreach\OutreachWindowService $window)
    {
        $data = $request->validate([
            'channel' => 'required|in:whatsapp,email',
            'subject' => 'nullable|string|max:1000',
            'body'    => 'nullable|string|max:20000',
        ]);

        // AT-117 §4a — send-window lock (server-side; the UI also disables the
        // button). The contact inline "WhatsApp" box launches the chat client-side
        // and records the dispatch here, so refusing the record out-of-window is
        // the server-side enforcement for this surface.
        if ($data['channel'] === 'whatsapp') {
            $agency = \App\Models\Agency::find($contact->agency_id ?? auth()->user()?->effectiveAgencyId());
            if ($agency && !$window->isSendAllowed($agency)) {
                return response()->json([
                    'message'             => $window->blockedMessage($agency),
                    'send_window_blocked' => true,
                ], 422);
            }
        }

        $communication = $logger->log(
            $contact,
            $data['channel'],
            $data['subject'] ?? null,
            $data['body'] ?? null,
            auth()->id()
        );

        // Part 4 — make the comms-tile quick-send visible on the Outreach &
        // Canvassing board (it writes only a provisional `communications` row and
        // previously fired no event). Source-tagged as `comms_tile` by the feed.
        $agencyId = (int) ($contact->agency_id ?? auth()->user()?->effectiveAgencyId() ?? 0);
        if ($agencyId > 0) {
            event(new \App\Events\Contact\CommsTileMessageSent(
                contact: $contact,
                channel: $data['channel'],
                actorUserId: auth()->id(),
                agencyId: $agencyId,
                communicationId: $communication->id ?? null,
            ));
        }

        // The logger advanced last_contacted_at on this same instance.
        $last = $contact->last_contacted_at ?? now();

        return response()->json([
            'count'                   => $contact->outboundCommCount($data['channel']),
            'last_contacted'          => $last->format('d M Y H:i'),
            'last_contacted_relative' => $last->diffForHumans(),
        ]);
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect()->route('corex.contacts.index')->with('success', 'Contact deleted.');
    }

    public function recordConsent(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'consent_type' => 'required|in:fica_processing,marketing_communications,data_sharing,channel_email,channel_sms,channel_whatsapp,channel_call',
            'decision'     => 'nullable|in:given,declined',
            'method'       => 'nullable|in:verbal,written,electronic,signed_document',
        ]);

        $contact->setConsent(
            $data['consent_type'],
            $data['decision'] ?? \App\Models\ContactConsentRecord::DECISION_GIVEN,
            $data['method'] ?? 'electronic',
            auth()->id(),
            'agent_web',
        );

        return back()->with('success', 'Consent updated.')->with('tab', 'consent');
    }

    public function revokeConsent(Request $request, Contact $contact)
    {
        $request->validate([
            'consent_type' => 'required|in:fica_processing,marketing_communications,data_sharing,channel_email,channel_sms,channel_whatsapp,channel_call',
            'reason' => 'nullable|string|max:500',
        ]);

        $contact->clearConsent(
            $request->input('consent_type'),
            auth()->id(),
            $request->input('reason')
        );

        return back()->with('success', 'Consent cleared.')->with('tab', 'consent');
    }

    /**
     * Permanently purge every contact in the active agency — including
     * soft-deleted ones — together with all contact-owned related records,
     * so nothing is left orphaned.
     *
     * This is a hard delete and deliberately violates the "no hard deletes"
     * non-negotiable. It is restricted to super admins and explicitly
     * authorised as a system-maintenance escape hatch. Scope is the active
     * agency only — tenant isolation is never crossed.
     *
     * Related tables are resolved from the Contact model's own relationship
     * definitions rather than hard-coded, so the purge cannot silently drift
     * out of sync when a new relationship is added.
     */
    public function destroyAll(Request $request)
    {
        abort_unless($request->user()?->effectiveRole() === 'super_admin', 403);

        // Active-agency contact ids, including soft-deleted.
        $contactIds = Contact::withTrashed()->pluck('id');
        $count = $contactIds->count();

        if ($count === 0) {
            return redirect()->route('corex.contacts.index')->with('success', 'No contacts to delete.');
        }

        $proto = new Contact;

        // HasMany children that belong exclusively to a contact.
        $childRelations = [
            $proto->contactNotes(),
            $proto->testimonials(),
            $proto->legacyDocuments(),
            $proto->ficaSubmissions(),
            $proto->matches(),
            $proto->consentRecords(),
            $proto->accessLog(),
            $proto->buyerActivityLog(),
            $proto->buyerStateTransitions(),
            $proto->buyerPropertyViews(),
        ];

        // BelongsToMany pivots keyed on the contact.
        $pivotRelations = [
            $proto->tags(),
            $proto->parentTypes(),
            $proto->documents(),
            $proto->signedDocuments(),
            $proto->properties(),
        ];

        \DB::transaction(function () use ($proto, $childRelations, $pivotRelations, $contactIds) {
            foreach ($childRelations as $relation) {
                \DB::table($relation->getRelated()->getTable())
                    ->whereIn($relation->getForeignKeyName(), $contactIds)
                    ->delete();
            }

            foreach ($pivotRelations as $relation) {
                \DB::table($relation->getTable())
                    ->whereIn($relation->getForeignPivotKeyName(), $contactIds)
                    ->delete();
            }

            // Morph pivot shared across pillars — only remove contact-linked rows.
            $links = $proto->calendarEventLinks();
            \DB::table($links->getRelated()->getTable())
                ->where($links->getMorphType(), $proto->getMorphClass())
                ->whereIn($links->getForeignKeyName(), $contactIds)
                ->delete();

            Contact::withTrashed()->whereIn('id', $contactIds)->forceDelete();
        });

        // Audit the purge — this is the single most destructive action in the
        // module (a hard delete). The contact access log is per-record, so a bulk
        // purge is recorded to the application log with full actor/agency context.
        \Illuminate\Support\Facades\Log::warning('Contacts bulk-purged (destroyAll)', [
            'actor_user_id' => $request->user()?->id,
            'actor_role'    => $request->user()?->effectiveRole(),
            'agency_id'     => $request->user()?->effectiveAgencyId(),
            'contact_count' => $count,
            'ip'            => $request->ip(),
        ]);

        return redirect()->route('corex.contacts.index')->with('success', "{$count} contacts permanently deleted.");
    }

    public function syncTags(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'tag_ids'   => 'nullable|array',
            'tag_ids.*' => 'integer|exists:contact_tags,id',
        ]);

        $newTagIds = $data['tag_ids'] ?? [];

        // AT-79: route through the shared sync so the multi-parent pivot + the
        // primary-type mirror stay consistent (a sub-tag implies its parent).
        // Existing parent assignments are preserved.
        $existingParentIds = $contact->parentTypes()->pluck('contact_types.id')->all();
        $newlyAttached = $contact->syncTypeAssignments($existingParentIds, $newTagIds);

        // Domain event — ContactTagged for each newly attached tag.
        if (!empty($newlyAttached)) {
            $tagNames = ContactTag::whereIn('id', $newlyAttached)->pluck('name', 'id');
            foreach ($newlyAttached as $tagId) {
                event(new \App\Events\Contact\ContactTagged(
                    contact: $contact,
                    tag: (string) ($tagNames[$tagId] ?? $tagId),
                    actorUserId: auth()->id(),
                ));
            }
        }

        return redirect()->route('corex.contacts.show', $contact)->with('success', 'Tags updated.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function agentList(): \Illuminate\Support\Collection
    {
        /** @var User $user */
        $user      = auth()->user();
        $dataScope = PermissionService::getDataScope($user, 'contacts');

        $query = User::agencyMembers()->orderBy('name')->where('is_active', 1);

        if ($dataScope === 'branch') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        } elseif ($dataScope !== 'all') {
            $query->where('id', $user->id);
        }

        return $query->get(['id', 'name', 'email']);
    }
}
