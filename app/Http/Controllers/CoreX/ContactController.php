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

        if ($canPickAgent) {
            if ($filterAgentId !== '') {
                $query->where('created_by_user_id', (int) $filterAgentId);
            } elseif ($dataScope === 'branch' && $user->branch_id) {
                $query->whereHas('createdBy', fn($q) => $q->where('branch_id', $user->branch_id));
            }
            // 'all' scope with no filter = show all contacts
        } else {
            // 'own' scope: agents see only their own
            $query->where('created_by_user_id', $user->id);
        }

        if ($request->filled('search')) {
            $words = array_filter(explode(' ', trim($request->search)));
            foreach ($words as $word) {
                $query->where(function ($q) use ($word) {
                    $q->where('first_name', 'like', "%{$word}%")
                      ->orWhere('last_name',  'like', "%{$word}%")
                      ->orWhere('phone',      'like', "%{$word}%")
                      ->orWhere('email',      'like', "%{$word}%")
                      ->orWhere('id_number',  'like', "%{$word}%");
                });
            }
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
        $contacts     = $query->paginate($perPage)->withQueryString();
        $contactTypes = ContactType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        $agentList     = $canPickAgent ? $this->agentList()->values() : collect();
        $selectedAgent = ($canPickAgent && $filterAgentId !== '')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

        return view('corex.contacts.index', compact(
            'contacts', 'contactTypes', 'filterAgentId', 'agentList', 'selectedAgent', 'canPickAgent'
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

        $contact->load(['type', 'createdBy', 'agent', 'secondAgent', 'contactNotes.user', 'testimonials.user', 'testimonials.agent', 'documents.uploader', 'documents.documentType', 'documents.properties', 'properties', 'matches.createdBy', 'tags', 'communications']);

        // Agents in this contact's agency — for the "agent this testimonial is
        // about" selector on the Notes & Testimonials tab.
        $agencyAgents = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $contactTypes     = ContactType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
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
            $agencyId = $request->user()->effectiveAgencyId();
            if ($agencyId !== null && (int) $contact->agency_id === (int) $agencyId) {
                $timeline = app(\App\Http\Controllers\SellerOutreach\ContactTimelineController::class)
                    ->buildTimelineData((int) $agencyId, $contact);
                $outreachSends = $timeline['sends'];
                $outreachClickCounts = $timeline['clickCounts'];
                $outreachOutcomeOptions = $timeline['outcomeOptions'];
            }
        }

        // Communication Archive (AT-43) — this contact's linked archive comms
        // (email + WhatsApp). Gated by access_communication_archive: the archive
        // is a privacy-sensitive surface, so a user without that permission sees
        // no Communications tab at all. Deleted/purged rows excluded.
        $canViewComms = (bool) auth()->user()?->hasPermission('access_communication_archive');
        $contactComms = collect();
        if ($canViewComms) {
            $contactComms = \App\Models\Communications\Communication::query()
                ->whereNull('purged_at')
                ->whereHas('links', function ($q) use ($contact) {
                    $q->where('linkable_type', \App\Models\Contact::class)
                      ->where('linkable_id', $contact->id);
                })
                ->orderByDesc('occurred_at')
                ->limit(200)
                ->get();
        }

        // AT-59 — tile counts DERIVE from the communications archive (outbound,
        // provisional + confirmed), not the legacy scalar columns. The relation
        // is eager-loaded above so these are computed in memory (no N+1).
        $waSent    = $contact->outboundCommCount(\App\Models\Communications\Communication::CHANNEL_WHATSAPP);
        $emailSent = $contact->outboundCommCount(\App\Models\Communications\Communication::CHANNEL_EMAIL);

        return view('corex.contacts.show', compact('contact', 'contactTypes', 'contactTags', 'matchCategories', 'matchTypes', 'featureOptions', 'documentTypes', 'driveLinkedGroups', 'driveUnlinkedDocs', 'drivePropertyMap', 'buyerViewings', 'sellerViewings', 'buyerUpcoming', 'buyerPast', 'sellerUpcoming', 'sellerPast', 'viewingsCount', 'outreachSends', 'outreachClickCounts', 'outreachOutcomeOptions', 'agencyAgents', 'canViewComms', 'contactComms', 'waSent', 'emailSent'));
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

        // Drop ONLY the role-based ContactScope — an agent with 'own' data scope
        // can't see contacts captured by other agents, but a duplicate is still a
        // duplicate. AgencyScope stays on (non-negotiable #7), so the match is
        // agency-wide yet agency-isolated, mirroring the server-side store()
        // check (ContactDuplicateService::findDuplicates). Without this the agent
        // gets a green light here and a hard block on submit.
        $duplicate = Contact::withoutGlobalScope(\App\Models\Scopes\ContactScope::class)
            ->with(['createdBy', 'agent'])
            ->whereNull('purged_at')
            ->where(function ($q) use ($phone, $email) {
                if ($phone) {
                    $q->where('phone', $phone);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
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

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|max:30',
            'email'           => 'nullable|email|max:150',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'notes'           => 'nullable|string|max:1000',
            // Optional SA ID number, captured with a POPIA audit trail.
            'id_number'       => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
            // Duplicate bypass fields
            'bypass_duplicate_check' => 'nullable|boolean',
            'override_reason'        => 'nullable|string|max:500',
        ]);

        // Pull the ID out before the duplicate check (matches on phone/email/name)
        // and re-attach it with audit fields once we're past the dupe guard.
        $idNumber = !empty($data['id_number']) ? preg_replace('/\s+/', '', (string) $data['id_number']) : null;
        unset($data['id_number']);

        $user = auth()->user();
        $agencyId = $user->effectiveAgencyId() ?? 1;
        $service = app(ContactDuplicateService::class);

        // Skip duplicate check if explicitly bypassed (user already chose "create anyway")
        if (empty($data['bypass_duplicate_check'])) {
            $duplicates = $service->findDuplicates($data, $agencyId);

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

        $contact = Contact::create($data);

        return redirect()->route('corex.contacts.index')->with('success', 'Contact added successfully.');
    }

    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|max:30',
            'email'           => 'nullable|email|max:150',
            'contact_type_id' => 'nullable|exists:contact_types,id',
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
            'loaded_at'       => 'nullable|date',
            'modified_at'     => 'nullable|date',
            'tag_ids'         => 'nullable|array',
            'tag_ids.*'       => 'integer|exists:contact_tags,id',
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
            // AT-60 — structured address. All optional; partial addresses
            // allowed (street with no unit, complex with no street, etc.).
            // The legacy free-text `address` is no longer an input — it is
            // auto-composed from these fields on save (ContactObserver::saving).
            'unit_number'        => 'nullable|string|max:50',
            'floor_number'       => 'nullable|string|max:50',
            'unit_section_block' => 'nullable|string|max:150',
            'complex_name'       => 'nullable|string|max:150',
            'street_number'      => 'nullable|string|max:50',
            'street_name'        => 'nullable|string|max:200',
            'suburb'             => 'nullable|string|max:120',
            'city'               => 'nullable|string|max:120',
            'province'           => 'nullable|string|max:120',
            // P24 location ids come from the shared picker under the
            // 'contact_addr' prefix; validated as real references when present.
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
        // NAME typed but not matched to a record leaves its id empty. The picker
        // already clears the name client-side when unmatched, but a JS-off or
        // tampered POST could carry a name with no id — reject it clearly rather
        // than silently storing an unlinkable suburb.
        $danglers = [];
        foreach (['province' => 'p24_province_id', 'city' => 'p24_city_id', 'suburb' => 'p24_suburb_id'] as $name => $idKey) {
            if (filled($data[$name] ?? null) && empty($data[$idKey])) {
                $danglers[$name] = "Pick a {$name} from the Property24 list, or clear it.";
            }
        }
        if (!empty($danglers)) {
            throw \Illuminate\Validation\ValidationException::withMessages($danglers);
        }

        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);

        // A co-agent without a primary is meaningless — collapse it.
        if (array_key_exists('agent_id', $data) && empty($data['agent_id'])) {
            $data['second_agent_id'] = null;
        }

        // Transaction-wrapped so a partial failure (e.g. tag sync) rolls the
        // whole address save back cleanly — no half-written record.
        \DB::transaction(function () use ($contact, $data, $tagIds) {
            $contact->update($data);
            $previousTagIds = $contact->tags()->pluck('contact_tags.id')->all();
            $contact->tags()->sync($tagIds);

            // Domain event — ContactTagged for each newly attached tag.
            // Spec: .ai/specs/corex-domain-events-spec.md
            $newlyAttached = array_diff(array_map('intval', $tagIds), array_map('intval', $previousTagIds));
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
        });

        // Redirect to show page if coming from there, otherwise index
        if ($request->has('_from_show')) {
            return redirect()->route('corex.contacts.show', $contact)->with('success', 'Contact updated.');
        }

        return redirect()->route('corex.contacts.index')->with('success', 'Contact updated.');
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
    public function incrementChannel(Request $request, Contact $contact, \App\Services\Communications\OutboundProvisionalLogger $logger)
    {
        $data = $request->validate([
            'channel' => 'required|in:whatsapp,email',
            'subject' => 'nullable|string|max:1000',
            'body'    => 'nullable|string|max:20000',
        ]);

        $logger->log(
            $contact,
            $data['channel'],
            $data['subject'] ?? null,
            $data['body'] ?? null,
            auth()->id()
        );

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
        $previousTagIds = $contact->tags()->pluck('contact_tags.id')->all();
        $contact->tags()->sync($newTagIds);

        // Domain event — ContactTagged for each newly attached tag.
        $newlyAttached = array_diff(array_map('intval', $newTagIds), array_map('intval', $previousTagIds));
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
