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
use Illuminate\Http\Request;

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
        // Contact-details Phase 2 — the label list for the phone/email repeaters.
        $contactIdentifierLabels = \App\Models\ContactIdentifierLabel::where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();

        $agentList     = $canPickAgent ? $this->agentList()->values() : collect();
        $selectedAgent = ($canPickAgent && $filterAgentId !== '')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

        return view('corex.contacts.index', compact(
            'contacts', 'contactTypes', 'contactIdentifierLabels', 'filterAgentId', 'agentList', 'selectedAgent', 'canPickAgent'
        ));
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

        // AT-321-C — unlimited CSV export of the contact audit trail (History tab).
        if ($request->get('export') === 'csv' && $request->get('tab') === 'history') {
            $rows = \App\Models\ContactAuditLog::where('contact_id', $contact->id)
                ->with('user')->orderByDesc('created_at')->get();
            $csv = "Timestamp,Actor,Source,Category,Event Type,Summary,Before,After\n";
            foreach ($rows as $r) {
                $actor = $r->user?->name ?? ($r->actor_label ?? 'System');
                $csv .= '"' . $r->created_at->toIso8601String() . '","' . addslashes($actor) . '","'
                    . addslashes($r->source ?? '') . '","' . $r->event_category . '","' . $r->event_type . '","'
                    . addslashes($r->human_summary ?? '') . '","' . addslashes(json_encode($r->old_values ?? [])) . '","'
                    . addslashes(json_encode($r->new_values ?? [])) . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="contact-' . $contact->id . '-audit-log.csv"',
            ]);
        }

        $contact->load(['type', 'parentTypes', 'createdBy', 'agent', 'secondAgent', 'contactNotes.user', 'testimonials.user', 'testimonials.agent', 'documents.uploader', 'documents.documentType', 'documents.properties', 'properties', 'matches.createdBy', 'tags', 'communications', 'phones', 'emails', 'representatives', 'representedEntities', 'deadEndFlag']);

        // Agents in this contact's agency — for the "agent this testimonial is
        // about" selector on the Notes & Testimonials tab.
        $agencyAgents = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $contactTypes     = ContactType::parents()->with('subTags')->get()->unique('name')->values();
        // Contact-details Phase 2 — the label list for the phone/email repeaters.
        $contactIdentifierLabels = \App\Models\ContactIdentifierLabel::where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();
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
            // 2026-08-18 (Johan, AT-calendar-buttons §D) — unioned across BOTH
            // vocabularies (outcome = buyer-facing, lp_outcome = seller-facing).
            // agency_feedback_options.id is the primary key, globally unique across
            // categories, so one id->label map safely resolves either vocabulary —
            // fixes 2ee1159ad's actor_role split silently blanking this label
            // whenever it wrote from the OTHER category than this hardcoded one.
            $outcomeLabels = \DB::table('agency_feedback_options')->whereIn('category', ['outcome', 'lp_outcome'])->pluck('label', 'id');

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
                    // 2026-08-19 (Johan) — "same places feedback surfaces." A dismissed
                    // appointment has no feedback row to show, but the reason it was
                    // dismissed (buyer bought elsewhere, no-show, etc.) belongs here for
                    // exactly the same reason feedback does — the next agent needs it.
                    'dismissal_reason' => $ev->status === 'dismissed' ? $ev->dismissalReasonLabel() : null,
                    'feedback' => $fb ? [
                        // per-property-mode captures (feedback_kind=listing_presentation)
                        // never populate outcome_option_id — they store the outcome as a
                        // label string in kind_specific_data.outcome instead. Fall back to
                        // that so per-property feedback (now viewing's default too, §C)
                        // still renders a label instead of going blank.
                        'outcome_label' => $fb->outcome_option_id
                            ? $outcomeLabels->get($fb->outcome_option_id)
                            : (json_decode($fb->kind_specific_data ?? 'null', true)['outcome'] ?? null),
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
        // Referential guard (2026-08-14) — read the pivot THROUGH properties so soft-deleted /
        // removed properties never surface a broken link (mirrors the belongsToMany relation used
        // for the Properties list). Prevents an archived/merged property leaking into this raw read.
        $ownedPropertyIds = \DB::table('contact_property as cp')
            ->join('properties as p', 'p.id', '=', 'cp.property_id')
            ->where('cp.contact_id', $contact->id)
            ->whereNull('p.deleted_at')
            ->pluck('cp.property_id');

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
                // 2026-08-18 (Johan, AT-calendar-buttons §D) — see the matching buyer-
                // perspective comment above: unioned across both vocabularies.
                $sOutcomes = \DB::table('agency_feedback_options')->whereIn('category', ['outcome', 'lp_outcome'])->pluck('label', 'id');

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
                        // See the buyer-perspective block above — same reasoning.
                        'dismissal_reason' => $sEv->status === 'dismissed' ? $sEv->dismissalReasonLabel() : null,
                        'feedback' => $sFb ? [
                            // See the buyer-perspective block above — per-property
                            // captures store the outcome as a label string in
                            // kind_specific_data.outcome, not outcome_option_id.
                            'outcome_label' => $sFb->outcome_option_id
                                ? $sOutcomes->get($sFb->outcome_option_id)
                                : (json_decode($sFb->kind_specific_data ?? 'null', true)['outcome'] ?? null),
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

        $featureOptions = array_merge(
            \App\Http\Controllers\CoreX\ContactMatchController::FEATURE_OPTIONS,
            \App\Http\Controllers\CoreX\ContactMatchController::POOL_TYPE_OPTIONS
        );

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

        // Contact-details Phase 4 — the "could not send" flow needs a per-send
        // list to act on (none existed before this — the comms-tile path had no
        // individual-send UI at all). Reuses the ALREADY eager-loaded
        // `communications` relation (no extra query), most recent first, capped
        // at 15 — this is an action list, not the full archive (that's the
        // Communications tab).
        $recentSends = $contact->communications
            ->where('direction', \App\Models\Communications\Communication::DIRECTION_OUTBOUND)
            ->whereNull('purged_at')
            ->sortByDesc('occurred_at')
            ->take(15)
            ->values();

        // Audit strip for the panel above — reads the SAME domain_event_log the
        // 3 Phase 4 events auto-write to (no parallel audit table). Grouped by
        // communication_id in the Blade view so each row can show its own chain.
        $sendAuditLog = \Illuminate\Support\Facades\DB::table('domain_event_log')
            ->where('subject_type', \App\Models\Contact::class)
            ->where('subject_id', $contact->id)
            ->whereIn('event_name', [
                \App\Events\Communication\CommunicationMarkedNotDelivered::class,
                \App\Events\Communication\CommunicationSendStatusReverted::class,
                \App\Events\Communication\CommunicationResent::class,
            ])
            ->orderBy('occurred_at')
            ->get()
            ->map(function ($row) {
                $row->context = json_decode($row->context, true) ?? [];
                return $row;
            });

        $sendAuditActors = \App\Models\User::whereIn('id', $sendAuditLog->pluck('actor_user_id')->filter()->unique())
            ->pluck('name', 'id');

        // AT-136 — the viewing agent's WhatsApp-capture decision for THIS contact
        // (per-agent; SEPARATE from AT-125 marketing opt-out). null = no WA match yet.
        $myCaptureStatus = $viewer
            ? optional(\App\Models\Communications\AgentCaptureConsent::query()
                ->where('agent_user_id', $viewer->id)->where('contact_id', $contact->id)->first())->status
            : null;

        // AT-321-C — FULL contact audit trail for the History tab, paginated (no
        // cap). CSV export above is the unlimited one-shot. Page links keep tab=history.
        // AT-321-C — History tab "Include system trail" toggle. Default OFF shows
        // user changes only; the db-trigger backstop rows (source='db-trigger')
        // are hidden unless the toggle is ticked.
        $includeSystem = request()->boolean('include_system');
        $fullAuditLog = \App\Models\ContactAuditLog::where('contact_id', $contact->id)
            ->with('user')
            ->when(!$includeSystem, fn ($q) => $q->where(fn ($w) => $w->whereNull('source')->orWhere('source', '<>', 'db-trigger')))
            ->orderByDesc('created_at')
            ->paginate(50, ['*'], 'history')
            ->appends(array_filter(['tab' => 'history', 'include_system' => $includeSystem ? 1 : null]));

        return view('corex.contacts.show', compact('contact', 'contactTypes', 'contactIdentifierLabels', 'contactTags', 'matchCategories', 'matchTypes', 'featureOptions', 'documentTypes', 'driveLinkedGroups', 'driveUnlinkedDocs', 'drivePropertyMap', 'buyerViewings', 'sellerViewings', 'buyerUpcoming', 'buyerPast', 'sellerUpcoming', 'sellerPast', 'viewingsCount', 'outreachSends', 'outreachClickCounts', 'outreachOutcomeOptions', 'agencyAgents', 'canViewComms', 'contactComms', 'contactThreads', 'commsViaGrant', 'canRequestComms', 'pendingCommsRequest', 'myCaptureStatus', 'waSent', 'emailSent', 'fullAuditLog', 'includeSystem', 'recentSends', 'sendAuditLog', 'sendAuditActors'));
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

    /** @return array<int,array{value:string,label:?string,is_primary:bool,country_iso:?string,dial_code:?string}> */
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
            // Contact-details Phase 1/2 — country_iso and label_id are passed
            // through RAW/unresolved here. ContactIdentifierService::syncKind()
            // is THE canonical resolution point for both (derives dial_code from
            // country_iso; verifies label_id ownership against the contact's own
            // agency) — every writer (form, API, importer, console) gets the
            // same resolution, not just this one. A posted dial_code, if any,
            // is never read/trusted anywhere.
            $out[] = [
                'value'      => $value,
                'label'      => $label !== '' ? $label : null,
                'label_id'   => is_array($row) ? ($row['label_id'] ?? null) : null,
                'is_primary' => is_array($row) && filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'country_iso' => is_array($row) ? ($row['country_iso'] ?? null) : null,
                // Contact-details Phase 3 — WhatsApp designation, phone rows only.
                'is_whatsapp'         => is_array($row) && filter_var($row['is_whatsapp'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'is_primary_whatsapp' => is_array($row) && filter_var($row['is_primary_whatsapp'] ?? false, FILTER_VALIDATE_BOOLEAN),
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
        // #17 — foreign nationals: id_type='passport' captures a free passport number + a
        // directly-entered DOB (birthday) instead of a 13-digit SA ID. Absent/other id_type keeps
        // the existing validated SA-ID path, so existing SA-ID captures are unaffected.
        $isForeign = $request->input('id_type') === 'passport';

        $data = $request->validate([
            // Entity-type foundation (.ai/specs/contact-entity-type.md) — the
            // Contact Is radio. first_name/last_name are only required for a
            // natural person; entity_name only for an entity. The form hides
            // whichever set doesn't apply, so the absent side is never posted.
            'contact_kind'    => 'nullable|in:' . Contact::TYPE_NATURAL_PERSON . ',' . Contact::TYPE_ENTITY,
            'first_name'      => 'nullable|string|max:100|required_if:contact_kind,' . Contact::TYPE_NATURAL_PERSON,
            'last_name'       => 'nullable|string|max:100|required_if:contact_kind,' . Contact::TYPE_NATURAL_PERSON,
            'entity_name'     => 'nullable|string|max:255|required_if:contact_kind,' . Contact::TYPE_ENTITY,
            'entity_reg_no'   => 'nullable|string|max:255',
            // AT-125 — single fields kept for back-compat (external posters); the
            // form posts the repeatable phones[]/emails[] arrays below.
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'phones'              => 'nullable|array',
            'phones.*.value'      => 'nullable|string|max:30',
            'phones.*.label'      => 'nullable|string|max:60',
            'phones.*.is_primary' => 'nullable|boolean',
            // Contact-details Phase 1 — country dial prefix; unrecognised/absent
            // resolves to ZA in ContactIdentifierService::resolveCountry(), never trusted as-is.
            'phones.*.country_iso' => 'nullable|string|max:2',
            // Contact-details Phase 2 — managed label; ContactIdentifierService
            // re-verifies agency ownership, never trusts the exists() check alone.
            'phones.*.label_id'    => 'nullable|integer|exists:contact_identifier_labels,id',
            // Contact-details Phase 3 — WhatsApp designation, phone-only.
            'phones.*.is_whatsapp'         => 'nullable|boolean',
            'phones.*.is_primary_whatsapp' => 'nullable|boolean',
            'emails'              => 'nullable|array',
            'emails.*.value'      => 'nullable|email|max:150',
            'emails.*.label'      => 'nullable|string|max:60',
            'emails.*.is_primary' => 'nullable|boolean',
            'emails.*.label_id'   => 'nullable|integer|exists:contact_identifier_labels,id',
            // Type/tag assignments arrive via the pop-up picker and are applied
            // after creation (applyTypeAssignments) — not a single column.
            'notes'           => 'nullable|string|max:1000',
            // Optional SA ID number, captured with a POPIA audit trail. Only
            // meaningful for a natural person — the form doesn't post it for
            // an entity, so this rule doesn't need a contact_kind condition.
            // #17 — SA ID validated for SA persons; a foreign national's passport is a free
            // string, and their DOB is captured directly (birthday) since it can't be derived.
            'id_type'         => ['nullable', \Illuminate\Validation\Rule::in(['sa_id', 'passport'])],
            'id_number'       => $isForeign
                ? ['nullable', 'string', 'max:50']
                : ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
            'birthday'        => ['nullable', 'date', 'required_if:id_type,passport'],
            // Duplicate bypass fields
            'bypass_duplicate_check' => 'nullable|boolean',
            'override_reason'        => 'nullable|string|max:500',
        ]);

        $data['contact_kind'] = $data['contact_kind'] ?? Contact::TYPE_NATURAL_PERSON;

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
                $agencyId,
                null,
                $data['entity_reg_no'] ?? null
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
            // Entity-type foundation (.ai/specs/contact-entity-type.md) — see
            // store() for the same shape/reasoning.
            'contact_kind'    => 'nullable|in:' . Contact::TYPE_NATURAL_PERSON . ',' . Contact::TYPE_ENTITY,
            'first_name'      => 'nullable|string|max:100|required_if:contact_kind,' . Contact::TYPE_NATURAL_PERSON,
            'last_name'       => 'nullable|string|max:100|required_if:contact_kind,' . Contact::TYPE_NATURAL_PERSON,
            'entity_name'     => 'nullable|string|max:255|required_if:contact_kind,' . Contact::TYPE_ENTITY,
            'entity_reg_no'   => 'nullable|string|max:255',
            // AT-125 — single fields kept for back-compat; the form posts arrays.
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'phones'              => 'nullable|array',
            'phones.*.value'      => 'nullable|string|max:30',
            'phones.*.label'      => 'nullable|string|max:60',
            'phones.*.is_primary' => 'nullable|boolean',
            // Contact-details Phase 1 — country dial prefix; unrecognised/absent
            // resolves to ZA in ContactIdentifierService::resolveCountry(), never trusted as-is.
            'phones.*.country_iso' => 'nullable|string|max:2',
            // Contact-details Phase 2 — managed label; ContactIdentifierService
            // re-verifies agency ownership, never trusts the exists() check alone.
            'phones.*.label_id'    => 'nullable|integer|exists:contact_identifier_labels,id',
            // Contact-details Phase 3 — WhatsApp designation, phone-only.
            'phones.*.is_whatsapp'         => 'nullable|boolean',
            'phones.*.is_primary_whatsapp' => 'nullable|boolean',
            'emails'              => 'nullable|array',
            'emails.*.value'      => 'nullable|email|max:150',
            'emails.*.label'      => 'nullable|string|max:60',
            'emails.*.is_primary' => 'nullable|boolean',
            'emails.*.label_id'   => 'nullable|integer|exists:contact_identifier_labels,id',
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
            // #17 — id_type discriminates SA ID vs a foreign passport; id_number widened to 50 to
            // hold a passport. Kept lenient on edit (no SA-ID checksum) so legacy contacts still save.
            'id_type'         => ['nullable', \Illuminate\Validation\Rule::in(['sa_id', 'passport'])],
            'id_number'       => 'nullable|string|max:50',
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
            // Dead-end escape hatch — same ContactDeadEndFlag concept the MIC
            // compose screen uses (ComposeSellerService::markSellerDeadEnd),
            // surfaced here so a contact record can be saved with no phone/email.
            'no_contact_details' => 'nullable|boolean',
        ]);

        // Defensive: if the radio somehow didn't post (JS disabled/bypassed),
        // keep the contact's existing kind rather than silently defaulting to
        // natural_person, which could wrongly flip an existing entity contact.
        $data['contact_kind'] = $data['contact_kind'] ?? $contact->contact_kind ?? Contact::TYPE_NATURAL_PERSON;

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
        // "No contact details available" — the same dead-end escape hatch the
        // MIC compose screen uses — bypasses the compulsory-identifier check.
        $noContactDetails = $request->boolean('no_contact_details');
        if ($hasIdentifierInput && $phones === [] && $emails === [] && ! $noContactDetails) {
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
        \DB::transaction(function () use ($contact, $data, $request, $hasIdentifierInput, $phones, $emails, $noContactDetails) {
            $contact->update($data);
            if ($hasIdentifierInput) {
                app(\App\Services\Contacts\ContactIdentifierService::class)->syncIdentifiers($contact, $phones, $emails);
            }
            $this->applyTypeAssignments($contact, $request);

            // Reconcile the dead-end flag against the identifiers this save
            // actually leaves in place — real contact details always win, even
            // if the tick is left checked. Same ContactDeadEndFlag row the MIC
            // compose screen reads/writes (ComposeSellerService), written here
            // directly so the 'source' correctly reflects this entry point —
            // a contact flagged here reads correctly from the compose screen too.
            $hasContactDetails = $hasIdentifierInput
                ? ($phones !== [] || $emails !== [])
                : ($contact->phones()->exists() || $contact->emails()->exists() || filled($contact->phone) || filled($contact->email));
            if ($hasContactDetails) {
                \App\Models\ContactDeadEndFlag::withoutGlobalScopes()
                    ->where('agency_id', $contact->agency_id)
                    ->where('contact_id', $contact->id)
                    ->delete();
            } elseif ($noContactDetails) {
                \App\Models\ContactDeadEndFlag::updateOrCreate(
                    ['contact_id' => $contact->id],
                    [
                        'agency_id'          => $contact->agency_id,
                        'property_id'        => null,
                        'reason'             => \App\Models\ContactDeadEndFlag::REASON_NO_RECORD,
                        'source'             => 'contact_record',
                        'created_by_user_id' => $request->user()->id,
                    ],
                );
            }
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

        // AT-372 — "Mark as Now" / "Pick Date" are EXPLICIT contacted actions: record
        // them as the first-class contacted signal (markContacted) so they persist and
        // are never wiped by a later send's recompute, then re-derive last_contacted_at.
        $contact->markContacted($data['last_contacted_at']);

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
            'channel'          => 'required|in:whatsapp,email',
            'subject'          => 'nullable|string|max:1000',
            'body'             => 'nullable|string|max:20000',
            // Outreach number/email selector — the agent may target a NON-default
            // number/email; resolved below (scoped to THIS contact) and passed as
            // the logger's recipientValue so the archived row reflects what was
            // actually used, not always the primary/WhatsApp designation.
            'contact_phone_id' => 'nullable|integer',
            'contact_email_id' => 'nullable|integer',
            // AT-323 — set when this send is a Resend of an earlier not_delivered row,
            // so the new attempt is lineage-linked and audited as a resend.
            'resent_from_communication_id' => 'nullable|integer',
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

        $recipientValue = null;
        if ($data['channel'] === 'whatsapp' && !empty($data['contact_phone_id'])) {
            $recipientValue = $contact->phones()->find($data['contact_phone_id'])?->phone;
        } elseif ($data['channel'] === 'email' && !empty($data['contact_email_id'])) {
            $recipientValue = $contact->emails()->find($data['contact_email_id'])?->email;
        }

        $resentFrom = !empty($data['resent_from_communication_id']) ? (int) $data['resent_from_communication_id'] : null;

        $communication = $logger->log(
            $contact,
            $data['channel'],
            $data['subject'] ?? null,
            $data['body'] ?? null,
            auth()->id(),
            resentFromCommunicationId: $resentFrom,
            recipientValue: $recipientValue,
        );

        // AT-323 — a WhatsApp "send" is client-side click-to-chat: CoreX opens WhatsApp but
        // never transmits, so it has NO delivery signal of its own. The WhatsApp row is
        // therefore born NOT counted (send_status=not_delivered) and stays uncounted until the
        // agent answers the ALWAYS-SHOWN "Did you send it?" modal with "Yes"
        // (markCommunicationSent → sent). That is the ONLY way a WhatsApp send reaches sent, so
        // the "messages sent" counter can never run ahead of what the agent actually confirmed
        // (INVARIANTS 2 & 3). A born-'sent' default counted the message before it was confirmed
        // — the bug this fixes. Email is exempt: it is system-sent (mailto) with no modal, so it
        // keeps the born-'sent' default and its own count.
        if ($data['channel'] === \App\Models\Communications\Communication::CHANNEL_WHATSAPP) {
            $communication->forceFill([
                'send_status' => \App\Models\Communications\Communication::SEND_STATUS_NOT_DELIVERED,
            ])->save();
            // last_contacted was optimistically touched by the logger; pull it back to the last
            // ACTUALLY-sent row so an unconfirmed send never advances "last contacted".
            $contact->recomputeLastContacted();
        }

        // AT-323 — a Resend re-runs the whole flow; audit it as a resend of the original.
        if ($resentFrom) {
            event(new \App\Events\Communication\CommunicationResent(
                contact: $contact,
                originalCommunicationId: $resentFrom,
                newCommunicationId: (int) $communication->id,
                channel: $communication->channel,
                actorUserId: auth()->id(),
                agencyId: (int) $communication->agency_id,
            ));
        }

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

        // The logger advanced last_contacted_at on this same instance; reload for the response.
        $contact->refresh();
        $last = $contact->last_contacted_at;

        return response()->json([
            'count'                   => $contact->outboundCommCount($data['channel']),
            'last_contacted'          => $last?->format('d M Y H:i'),
            'last_contacted_relative' => $last?->diffForHumans(),
            // AT-323 (option 2) — the provisional row's id, so the ALWAYS-SHOWN post-send
            // "Did it send? Yes/No" confirmation can flag THIS send not_delivered on "No"
            // (never a false "sent"). The row stays 'sent' optimistically until the agent answers.
            'communication_id'        => $communication->id ?? null,
            'send_status'             => $communication->refresh()->send_status,
        ]);
    }

    /**
     * Contact-details Phase 4 — flag a previously-recorded send as "could not
     * send / not delivered". Agent-initiated (WhatsApp is click-to-chat — CoreX
     * has no delivery signal of its own to detect this automatically); the
     * agent finds out on their own phone and reports it back here.
     */
    public function markCommunicationNotDelivered(
        Request $request,
        Contact $contact,
        \App\Models\Communications\Communication $communication,
        \App\Services\Communications\CommunicationSendStatusService $service
    ) {
        abort_unless($this->communicationBelongsToContact($communication, $contact), 404);

        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $service->markNotDelivered($communication, $contact, auth()->id(), $data['reason'] ?? null);

        // AT-323 — the post-send confirmation modal calls this over fetch(); answer JSON so the
        // tile can update the count in place. The existing form-post callers still get the redirect.
        if ($request->wantsJson()) {
            return response()->json([
                'ok'      => true,
                'count'   => $contact->outboundCommCount($communication->channel),
                'message' => 'Recorded as not sent — this contact is no longer counted as reached by that send.',
            ]);
        }

        return back()->with('success', 'Marked as could not send. The contact is no longer counted as reached by this send.');
    }

    /**
     * AT-323 — "Yes, I sent it" from the post-send modal. The ONLY path that marks a
     * click-to-chat send as sent (+1 the counter). No other control reaches sent — the
     * old "Revert to sent" (which flipped a not_delivered row to sent with NO modal, a
     * false-sent path) has been removed. Returns the recomputed count so the tile updates
     * live without a reload.
     */
    public function markCommunicationSent(
        Request $request,
        Contact $contact,
        \App\Models\Communications\Communication $communication,
        \App\Services\Communications\CommunicationSendStatusService $service
    ) {
        abort_unless($this->communicationBelongsToContact($communication, $contact), 404);

        $service->markSent($communication, $contact, auth()->id());

        return response()->json([
            'ok'    => true,
            'count' => $contact->outboundCommCount($communication->channel),
        ]);
    }

    /** Contact-details Phase 4 — a communication must actually belong to this contact (agency-scoped via the relation) before any status action touches it. */
    private function communicationBelongsToContact(\App\Models\Communications\Communication $communication, Contact $contact): bool
    {
        return $contact->communications()->where('communications.id', $communication->id)->exists();
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
