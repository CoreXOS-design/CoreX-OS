<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\DocumentType;
use App\Models\PropertySettingItem;
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

        // Agent filter with session persistence
        if ($request->has('agent_id')) {
            $raw           = $request->query('agent_id', '');
            $filterAgentId = $raw;
            session(['corex_contacts_agent_id' => $raw === '' ? 'all' : $raw]);
        } elseif ($canPickAgent) {
            $saved = session('corex_contacts_agent_id');
            if ($saved === null) {
                $filterAgentId = (string) $user->id;
                session(['corex_contacts_agent_id' => $filterAgentId]);
            } elseif ($saved === 'all') {
                $filterAgentId = '';
            } else {
                $filterAgentId = $saved;
            }
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
            $query->where('contact_type_id', $request->type);
        }

        $contacts     = $query->paginate(25)->withQueryString();
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

        $contact->load(['type', 'createdBy', 'contactNotes.user', 'documents.uploader', 'documents.documentType', 'documents.properties', 'properties', 'matches.createdBy', 'tags']);
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

        // Viewings & Feedback — buyer perspective: events where this contact is buyer/attendee
        $buyerViewings = collect();
        $buyerEventIds = \DB::table('calendar_event_links')
            ->where('linkable_type', \App\Models\Contact::class)
            ->where('linkable_id', $contact->id)
            ->whereIn('role', ['buyer_contact', 'attendee'])
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

        // Seller perspective: properties this contact owns, and feedback from buyers on those
        $sellerViewings = collect();
        $ownedPropertyIds = \DB::table('contact_property')
            ->where('contact_id', $contact->id)
            ->whereIn('role', ['owner', 'seller', 'landlord', 'lessor'])
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

        return view('corex.contacts.show', compact('contact', 'contactTypes', 'contactTags', 'matchCategories', 'matchTypes', 'featureOptions', 'documentTypes', 'driveLinkedGroups', 'driveUnlinkedDocs', 'drivePropertyMap', 'buyerViewings', 'sellerViewings', 'buyerUpcoming', 'buyerPast', 'sellerUpcoming', 'sellerPast', 'viewingsCount'));
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

        $duplicate = Contact::with('createdBy')
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
            'agent'          => optional($duplicate->createdBy)->name ?? 'Unknown',
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
            // Duplicate bypass fields
            'bypass_duplicate_check' => 'nullable|boolean',
            'override_reason'        => 'nullable|string|max:500',
        ]);

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
                    return redirect()->route('corex.contacts.show', $existing)
                        ->with('info', 'Existing contact found and linked automatically.');
                }

                // Return 422 with duplicates for modal display
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'duplicates' => $duplicates->map(fn($c) => [
                            'id' => $c->id,
                            'name' => $c->full_name,
                            'phone' => $mode === 'hard_block_request' ? null : $c->phone,
                            'email' => $mode === 'hard_block_request' ? null : $c->email,
                            'owner' => optional($c->createdBy)->name ?? 'Unknown',
                            'url' => route('corex.contacts.show', $c),
                        ]),
                        'mode' => $mode,
                        'match_field' => $match['field'],
                        'can_override' => $mode === 'hard_block_override' && in_array($user->effectiveRole(), ['admin', 'super_admin', 'owner']),
                    ], 422);
                }

                // Non-AJAX fallback: redirect back with duplicate info in session
                return back()->withInput()->with('duplicate_detected', [
                    'duplicates' => $duplicates->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->full_name,
                        'phone' => $mode === 'hard_block_request' ? null : $c->phone,
                        'email' => $mode === 'hard_block_request' ? null : $c->email,
                        'owner' => optional($c->createdBy)->name ?? 'Unknown',
                        'url' => route('corex.contacts.show', $c),
                    ])->toArray(),
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
            'birthday'        => 'nullable|date',
            'id_number'       => 'nullable|string|max:20',
            'address'         => 'nullable|string|max:500',
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
        ]);

        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);

        $contact->update($data);
        $contact->tags()->sync($tagIds);

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

    public function incrementChannel(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'channel' => 'required|in:whatsapp,email',
        ]);

        $field = $data['channel'] . '_count';
        $contact->increment($field);
        $contact->update(['last_contacted_at' => now()]);

        return response()->json([
            'count'            => $contact->fresh()->$field,
            'last_contacted'   => now()->format('d M Y H:i'),
            'last_contacted_relative' => now()->diffForHumans(),
        ]);
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect()->route('corex.contacts.index')->with('success', 'Contact deleted.');
    }

    public function recordConsent(Request $request, Contact $contact)
    {
        $request->validate([
            'consent_type' => 'required|in:fica_processing,marketing_communications,data_sharing,channel_email,channel_sms,channel_whatsapp,channel_call',
            'method' => 'nullable|in:verbal,written,electronic,signed_document',
        ]);

        $contact->recordConsent(
            $request->input('consent_type'),
            $request->input('method', 'electronic'),
            auth()->id()
        );

        return back()->with('success', 'Consent recorded.')->with('tab', 'consent');
    }

    public function revokeConsent(Request $request, Contact $contact)
    {
        $request->validate([
            'consent_type' => 'required|in:fica_processing,marketing_communications,data_sharing,channel_email,channel_sms,channel_whatsapp,channel_call',
            'reason' => 'nullable|string|max:500',
        ]);

        $contact->revokeConsent(
            $request->input('consent_type'),
            auth()->id(),
            $request->input('reason')
        );

        return back()->with('success', 'Consent revoked.')->with('tab', 'consent');
    }

    public function destroyAll()
    {
        $count = Contact::withTrashed()->count();
        // Hard-delete related records, then hard-delete all contacts (including soft-deleted)
        \DB::table('contact_tag')->delete();
        \DB::table('contact_notes')->delete();
        Contact::withTrashed()->forceDelete();

        return redirect()->route('corex.contacts.index')->with('success', "{$count} contacts permanently deleted.");
    }

    public function syncTags(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'tag_ids'   => 'nullable|array',
            'tag_ids.*' => 'integer|exists:contact_tags,id',
        ]);

        $contact->tags()->sync($data['tag_ids'] ?? []);

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
