<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealDocumentService;
use App\Services\DealV2\DealPipelineService;
use App\Services\PermissionService;
use Illuminate\Http\Request;

class DealV2Controller extends Controller
{
    public function __construct(private DealPipelineService $pipelineService)
    {
    }

    public function index(Request $request)
    {
        // Gate on the umbrella access permission (same key the sidebar "Deal
        // Register" link uses), NOT deals_v2.view. deals_v2.view is a scoped
        // action permission that governs WHICH deals are visible; on its own it
        // must not grant entry to the register when access_deal_register_v2 has
        // been revoked for the role.
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);

        $query = DealV2::with(['property', 'listingAgent', 'branch'])
            ->visibleTo(auth()->user());

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('property', fn ($pq) => $pq->where('address', 'like', "%{$search}%"));
            });
        }

        if ($dealType = $request->input('deal_type')) {
            $query->where('deal_type', $dealType);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($rag = $request->input('rag')) {
            $query->where('overall_rag', $rag);
        }

        $deals = $query->orderBy('created_at', 'desc')->paginate(25)->withQueryString();

        return view('deals-v2.index', compact('deals'));
    }

    /**
     * WS8 (§12) — the pipeline overview: scoped KPI cards + a non-draggable
     * milestone board. Branch_manager + admin only. The scope switcher is
     * server-authoritative — the requested scope is CLAMPED to the user's
     * permitted scope (DealV2::clampScope), so a switcher can only narrow.
     */
    public function overview(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.view_overview'), 403);
        $user = auth()->user();

        $permitted = PermissionService::getDataScope($user, 'deals_v2');
        $scope = DealV2::clampScope($request->input('scope'), $permitted);

        // Fresh scoped builder per aggregate (scopes mutate the query).
        $deals = fn () => DealV2::query()->visibleTo($user, $scope);
        $steps = fn () => DealStepInstance::query()
            ->whereHas('deal', fn ($q) => $q->visibleTo($user, $scope));

        // Avg days offer→registration across completed deals (PHP — small set).
        $completed = $deals()->whereNotNull('actual_registration')->get(['offer_date', 'actual_registration']);
        $avgDays = $completed
            ->map(fn ($d) => $d->offer_date && $d->actual_registration
                ? $d->offer_date->diffInDays($d->actual_registration) : null)
            ->filter(fn ($v) => $v !== null);

        $cards = [
            ['key' => 'active',       'label' => 'Active Deals',          'value' => $deals()->where('status', 'active')->count(),                                                    'rag' => null],
            ['key' => 'overdue',      'label' => 'Overdue Steps',         'value' => $steps()->where('status', 'overdue')->count(),                                                   'rag' => 'overdue'],
            ['key' => 'due_week',     'label' => 'Due This Week',         'value' => $steps()->where('status', 'active')->whereBetween('due_date', [now()->startOfDay(), now()->addWeek()->endOfDay()])->count(), 'rag' => 'amber'],
            ['key' => 'pending_reg',  'label' => 'Pending Registration',  'value' => $deals()->whereIn('status', ['active', 'granted'])->whereNull('actual_registration')->count(),   'rag' => null],
            ['key' => 'value',        'label' => 'Total Pipeline Value',  'value' => (float) $deals()->where('status', 'active')->sum('purchase_price'), 'format' => 'zar',            'rag' => null],
            ['key' => 'avg_days',     'label' => 'Avg Days to Registration', 'value' => $avgDays->isNotEmpty() ? (int) round($avgDays->avg()) : null,  'format' => 'days',            'rag' => null],
        ];

        // Non-draggable board: active deals grouped by current milestone. Milestone
        // derived in PHP from eager-loaded steps (no N+1).
        $board = $deals()->where('status', 'active')
            ->with(['stepInstances', 'property', 'listingAgent'])
            ->get()
            ->groupBy(function (DealV2 $d) {
                $steps = $d->stepInstances;
                $ms = $steps->where('is_milestone', true)->where('status', 'completed')->sortByDesc('position')->first()
                    ?? $steps->where('is_milestone', true)->whereIn('status', ['active', 'overdue'])->sortBy('position')->first();
                return $ms?->name ?? 'Not started';
            });

        return view('deals-v2.overview', [
            'cards'          => $cards,
            'board'          => $board,
            'scope'          => $scope,
            'permittedScope' => $permitted ?: 'own',
        ]);
    }

    /**
     * WS8 (§12) — CSV export of the filtered register. Row count == the filtered
     * result count (the verification gate). Reuses the index filters + scope.
     */
    public function exportCsv(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);
        $user = auth()->user();
        $scope = DealV2::clampScope($request->input('scope'), PermissionService::getDataScope($user, 'deals_v2'));

        $query = DealV2::with(['property', 'listingAgent'])->visibleTo($user, $scope);
        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('reference', 'like', "%{$search}%")
                ->orWhereHas('property', fn ($pq) => $pq->where('address', 'like', "%{$search}%")));
        }
        foreach (['deal_type' => 'deal_type', 'status' => 'status', 'rag' => 'overall_rag'] as $input => $col) {
            if ($val = $request->input($input)) {
                $query->where($col, $val);
            }
        }

        $filename = 'deal-register-' . now()->format('Ymd-His') . '.csv';
        $headers = ['Reference', 'Property', 'Agent', 'Type', 'Status', 'RAG', 'Expected Registration', 'Purchase Price'];

        $callback = function () use ($query, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            $query->orderBy('created_at', 'desc')->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $d) {
                    fputcsv($out, [
                        $d->reference,
                        $d->property?->address,
                        $d->listingAgent?->name,
                        $d->deal_type,
                        $d->status,
                        $d->overall_rag,
                        $d->expected_registration?->format('Y-m-d'),
                        $d->purchase_price,
                    ]);
                }
            });
            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function create()
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.create'), 403);

        $templates = DealPipelineTemplate::active()
            ->with('steps')
            ->orderBy('deal_type')
            ->orderBy('name')
            ->get()
            ->groupBy('deal_type');

        $branches = Branch::orderBy('name')->get();
        $agents = User::agencyMembers()->where('is_active', true)->orderBy('name')->get();

        // Pre-build template data for JS (avoid Blade closures in @json)
        $templatesJson = DealPipelineTemplate::active()
            ->with('steps')
            ->orderBy('deal_type')
            ->orderBy('name')
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'deal_type' => $t->deal_type,
                    'is_default' => $t->is_default,
                    'steps' => $t->steps->map(function ($s) {
                        return [
                            'id' => $s->id,
                            'name' => $s->name,
                            'position' => $s->position,
                            'is_locked' => $s->is_locked,
                            'is_milestone' => $s->is_milestone,
                            'completion_type' => $s->completion_type,
                            'trigger_type' => $s->trigger_type,
                            'trigger_step_id' => $s->trigger_step_id,
                            'days_offset' => $s->days_offset,
                            'status_trigger' => $s->status_trigger,
                        ];
                    })->values(),
                ];
            });

        $vatRate = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);

        return view('deals-v2.create', compact('branches', 'agents', 'templatesJson', 'vatRate'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.create'), 403);

        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'deal_type' => ['required', 'in:bond,cash,sale_of_2nd'],
            'pipeline_template_id' => ['required', 'exists:deal_pipeline_templates,id'],
            'purchase_price' => ['required', 'numeric', 'min:1'],
            'commission_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'total_commission_inc_vat' => ['nullable', 'numeric', 'min:0'],
            'commission_amount' => ['nullable', 'numeric', 'min:0'],
            'commission_vat' => ['nullable', 'numeric', 'min:0'],
            'offer_date' => ['required', 'date'],
            'linked_deal_id' => ['nullable', 'exists:deals_v2,id'],
            'notes' => ['nullable', 'string'],
            'listing_agent_id' => ['nullable', 'exists:users,id'],
            'selling_agent_id' => ['nullable', 'exists:users,id'],
            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*.contact_id' => ['required', 'exists:contacts,id'],
            'contacts.*.role' => ['required', 'in:buyer,seller,co_buyer,co_seller,conveyancer,bond_originator,other'],
            'listing_split_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'selling_split_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'listing_external' => ['nullable', 'boolean'],
            'listing_our_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'listing_external_agency' => ['nullable', 'string', 'max:255'],
            'selling_external' => ['nullable', 'boolean'],
            'selling_our_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'selling_external_agency' => ['nullable', 'string', 'max:255'],
            // Accept both formats: wizard JSON (agents[].side) or form (listing_agents[])
            'agents' => ['nullable', 'array'],
            'agents.*.user_id' => ['nullable', 'exists:users,id'],
            'agents.*.side' => ['nullable', 'in:listing,selling'],
            'agents.*.split_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'listing_agents' => ['nullable', 'array'],
            'listing_agents.*' => ['exists:users,id'],
            'selling_agents' => ['nullable', 'array'],
            'selling_agents.*' => ['exists:users,id'],
            'listing_override' => ['nullable', 'array'],
            'selling_override' => ['nullable', 'array'],
            'step_overrides' => ['nullable', 'array'],
        ]);

        // Calculate commission from inc VAT if provided
        if (!empty($data['total_commission_inc_vat'])) {
            $vatRate = (float) \App\Models\PerformanceSetting::get('vat_rate', 15) / 100;
            $incVat = (float) $data['total_commission_inc_vat'];
            $data['commission_amount'] = $incVat > 0 ? $incVat / (1 + $vatRate) : 0;
            $data['commission_vat'] = $incVat - $data['commission_amount'];
        }

        // Normalize form-based agents to pipeline service format
        if (!empty($data['listing_agents']) || !empty($data['selling_agents'])) {
            $data['agents'] = $this->buildAgentsFromForm($request);
        }

        // Set listing_agent_id from first listing agent if not explicitly set
        if (empty($data['listing_agent_id'])) {
            $firstListing = collect($data['agents'] ?? [])->firstWhere('side', 'listing');
            $data['listing_agent_id'] = $firstListing['user_id'] ?? auth()->id();
        }

        // Validate splits total 100
        $listingSplit = (float) ($data['listing_split_percent'] ?? 50);
        $sellingSplit = (float) ($data['selling_split_percent'] ?? 50);
        if (abs(($listingSplit + $sellingSplit) - 100) > 0.01) {
            return back()->withErrors("Listing + Selling split must equal 100.")->withInput();
        }

        $data['listing_external'] = $request->boolean('listing_external');
        $data['selling_external'] = $request->boolean('selling_external');
        $data['branch_id'] = auth()->user()->branch_id ?? Branch::first()?->id;
        $data['created_by_id'] = auth()->id();

        $deal = $this->pipelineService->createDeal($data);

        return redirect()->route('deals-v2.show', $deal)
            ->with('status', "Deal {$deal->reference} created successfully.");
    }

    public function show(DealV2 $deal)
    {
        // Same umbrella gate as index() — viewing a single deal is part of the
        // register browsing surface, so it requires access_deal_register_v2.
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);

        $deal->load([
            'property',
            'contacts',
            'agents',
            'stepInstances' => fn ($q) => $q->orderBy('position'),
            'stepInstances.documents',
            'stepInstances.completedBy',
            'activityLog' => fn ($q) => $q->with('user')->latest()->take(50),
            'pipelineTemplate',
            'listingAgent',
            'sellingAgent',
            'branch',
            'documents' => fn ($q) => $q->with('documentType', 'uploader'), // WS3 (D4) deal spine
            'distributions' => fn ($q) => $q->with('document.documentType', 'recipientContact', 'recipientProvider')->latest(), // WS4 (§8)
        ]);

        $user = auth()->user();
        $canEdit = $user->hasPermission('deals_v2.edit');
        $canApprove = $user->hasPermission('deals_v2.manage_pipeline') || $user->is_admin;
        $canOverrideDates = $user->hasPermission('deals_v2.override_dates');
        $canDistribute = $user->hasPermission('deals_v2.distribute_documents');

        // Doc-type picker + "satisfies which step" picker for the upload-onto-deal form.
        $documentTypes = \App\Models\DocumentType::query()->where('is_active', true)
            ->orderBy('sort_order')->get(['id', 'label']);
        $documentSteps = $deal->stepInstances
            ->whereIn('completion_type', ['document_upload', 'document_signed'])
            ->whereIn('status', ['active', 'not_started'])
            ->values();

        return view('deals-v2.show', compact(
            'deal', 'canEdit', 'canApprove', 'canOverrideDates', 'canDistribute', 'documentTypes', 'documentSteps'
        ));
    }

    /**
     * WS3 (D4) — upload a document directly onto a deal. Creates a unified
     * Document anchored to the deal (+ its property + contacts) in one pass and,
     * when the agent targets a step (or the document type matches an active
     * document step), auto-completes it through the pipeline engine.
     */
    public function storeDocument(Request $request, DealV2 $deal, DealDocumentService $dealDocumentService)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.edit'), 403);

        $data = $request->validate([
            'file'             => ['required', 'file', 'max:10240'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'link_step_id'     => ['nullable', 'integer'],
        ]);

        $file = $request->file('file');
        $disk = config('filesystems.default', 'local');
        $path = $file->store("deals/{$deal->id}/documents", $disk);

        $doc = $dealDocumentService->createDealDocument($deal, [
            'original_name'    => $file->getClientOriginalName(),
            'storage_path'     => $path,
            'disk'             => $disk,
            'mime_type'        => $file->getClientMimeType(),
            'size'             => $file->getSize(),
            'document_type_id' => $data['document_type_id'] ?? null,
            'source_type'      => 'deal_upload',
        ], auth()->user());

        // Explicit step target (agent chose "satisfies this step"), else fall
        // back to config-driven doc-type matching. Both no-op safely if nothing
        // is applicable — the document is still filed against the deal.
        $preferStep = null;
        if (! empty($data['link_step_id'])) {
            $preferStep = $deal->stepInstances()->whereKey($data['link_step_id'])->first();
        }
        $completedStep = $dealDocumentService->autoCompleteMatchingStep($deal, $doc, auth()->user(), $preferStep);

        $msg = 'Document filed against the deal';
        if ($completedStep && $completedStep->fresh()->status === 'completed') {
            $msg .= " and step \"{$completedStep->name}\" completed";
        }

        return redirect()->route('deals-v2.show', $deal->id)->with('status', $msg . '.');
    }

    /**
     * WS3 (D4) — authenticated download of a deal-anchored document. Documents
     * are served through this gated route (never a public docroot path): the
     * user must have register access AND the document must belong to this deal
     * in their agency. POPIA-conscious; deleted files 404 gracefully.
     */
    public function downloadDocument(DealV2 $deal, \App\Models\Document $document)
    {
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);
        abort_unless((int) $document->deal_id === (int) $deal->id, 404);

        $disk = \Illuminate\Support\Facades\Storage::disk($document->disk ?? 'local');
        abort_unless($disk->exists($document->storage_path), 404);

        return $disk->download($document->storage_path, $document->original_name);
    }

    public function searchProperties(Request $request)
    {
        $search = $request->input('q', '');
        if (strlen($search) < 2) {
            return response()->json([]);
        }

        // Match the flat `address` OR any of the structured address columns.
        // Newer records populate street_number/street_name/suburb/city and leave
        // the legacy flat `address` NULL — matching `address` alone silently drops
        // them (e.g. "99 Clarendon Road, Uvongo" with address=NULL). The closure
        // keeps the OR-group AND-ed with the model's global scopes (SoftDeletes,
        // Agency, Branch), which still apply.
        // Canonical property search + label (fix-the-class): unit+complex aware,
        // multi-term token AND, newest-first. Deal logic still needs agent id +
        // commission, so those ride along via toSearchResult()'s $extra.
        $properties = Property::query()
            ->searchAddress($search)
            ->with('agent')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (Property $p) => $p->toSearchResult([
                'address'            => $p->buildDisplayAddress(), // legacy key kept for the picker JS
                'price'              => $p->listing_price ?? $p->price ?? null,
                'listing_agent_id'   => $p->agent_id,
                'listing_agent_name' => $p->agent?->name,
                'commission_percent' => $p->commission_percent,
            ]));

        return response()->json($properties);
    }

    public function searchContacts(Request $request)
    {
        $search = $request->input('q', '');
        if (strlen($search) < 2) {
            return response()->json([]);
        }

        // AT-131 canonical contact search (all identifiers + relevance + newest-first).
        $contacts = Contact::query()
            ->with(['phones', 'emails', 'type', 'agent'])
            ->search($search)
            ->limit(15)
            ->get()
            ->map(fn (Contact $c) => $c->toSearchResult($search, [
                'name'  => $c->full_name,
                'email' => $c->email,
                'phone' => $c->phone,
            ]));

        return response()->json($contacts);
    }

    public function searchDeals(Request $request)
    {
        $search = $request->input('q', '');
        if (strlen($search) < 2) {
            return response()->json([]);
        }

        $deals = DealV2::with('property')
            ->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('property', fn ($pq) => $pq->where('address', 'like', "%{$search}%"));
            })
            ->where('status', 'active')
            ->limit(10)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'reference' => $d->reference,
                'property_address' => $d->property ? $d->property->address : null,
                'status' => $d->status,
            ]);

        return response()->json($deals);
    }

    public function getPropertyContacts(Property $property)
    {
        $contacts = $property->contacts()->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->full_name,
            'email' => $c->email,
            'phone' => $c->phone,
            'role' => $c->pivot->role ?? 'seller',
        ]);

        return response()->json($contacts);
    }

    public function edit(DealV2 $deal)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.edit'), 403);

        $deal->load(['property', 'contacts', 'agents', 'listingAgent', 'sellingAgent', 'branch',
            'stepInstances' => fn ($q) => $q->orderBy('position')]);

        $agents = User::agencyMembers()->where('is_active', true)->orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();
        $locked = $deal->isFinanciallyLocked();
        $vatRate = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);

        // Build selected IDs and percent overrides from pivot
        $listingSelectedIds = $deal->agents->filter(fn ($a) => ($a->pivot->side ?? '') === 'listing')
            ->pluck('id')->map(fn ($v) => (string) $v)->values()->all();
        $sellingSelectedIds = $deal->agents->filter(fn ($a) => ($a->pivot->side ?? '') === 'selling')
            ->pluck('id')->map(fn ($v) => (string) $v)->values()->all();

        $listingPercents = $deal->agents->filter(fn ($a) => ($a->pivot->side ?? '') === 'listing')
            ->mapWithKeys(fn ($a) => [(string) $a->id => $a->pivot->agent_split_percent])->toArray();
        $sellingPercents = $deal->agents->filter(fn ($a) => ($a->pivot->side ?? '') === 'selling')
            ->mapWithKeys(fn ($a) => [(string) $a->id => $a->pivot->agent_split_percent])->toArray();

        return view('deals-v2.form', compact(
            'deal', 'agents', 'branches', 'locked', 'vatRate',
            'listingSelectedIds', 'sellingSelectedIds', 'listingPercents', 'sellingPercents'
        ));
    }

    public function update(Request $request, DealV2 $deal)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.edit'), 403);

        $locked = $deal->isFinanciallyLocked();

        $rules = [
            'notes' => ['nullable', 'string'],
            'contacts' => ['nullable', 'array'],
            'contacts.*.contact_id' => ['required', 'exists:contacts,id'],
            'contacts.*.role' => ['required', 'in:buyer,seller,co_buyer,co_seller,conveyancer,bond_originator,other'],
        ];

        if (!$locked) {
            $rules = array_merge($rules, [
                'purchase_price' => ['required', 'numeric', 'min:1'],
                'total_commission_inc_vat' => ['required', 'numeric', 'min:0'],
                'commission_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'listing_split_percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'selling_split_percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'listing_external' => ['nullable', 'boolean'],
                'listing_our_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'listing_external_agency' => ['nullable', 'string', 'max:255'],
                'selling_external' => ['nullable', 'boolean'],
                'selling_our_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'selling_external_agency' => ['nullable', 'string', 'max:255'],
                'offer_date' => ['required', 'date'],
            ]);
        }

        $data = $request->validate($rules);

        // Update non-financial fields always
        $deal->update([
            'notes' => $data['notes'] ?? $deal->notes,
        ]);

        // Update contacts
        if (!empty($data['contacts'])) {
            $deal->contacts()->detach();
            foreach ($data['contacts'] as $c) {
                $deal->contacts()->attach($c['contact_id'], ['role' => $c['role']]);
            }
        }

        // Financial fields only if not locked
        if (!$locked) {
            $vatRate = (float) \App\Models\PerformanceSetting::get('vat_rate', 15) / 100;
            $incVat = (float) $data['total_commission_inc_vat'];
            $exVat = $incVat > 0 ? $incVat / (1 + $vatRate) : 0;
            $vat = $incVat - $exVat;

            // Validate splits total 100
            $listingSplit = (float) ($data['listing_split_percent'] ?? 50);
            $sellingSplit = (float) ($data['selling_split_percent'] ?? 50);
            if (abs(($listingSplit + $sellingSplit) - 100) > 0.01) {
                return back()->withErrors("Listing + Selling split must equal 100.")->withInput();
            }

            $deal->update([
                'purchase_price' => $data['purchase_price'],
                'commission_percentage' => $data['commission_percentage'] ?? null,
                'commission_amount' => $exVat,
                'commission_vat' => $vat,
                'listing_split_percent' => $listingSplit,
                'selling_split_percent' => $sellingSplit,
                'listing_external' => $request->boolean('listing_external'),
                'listing_our_share_percent' => $data['listing_our_share_percent'] ?? 100,
                'listing_external_agency' => $data['listing_external_agency'] ?? null,
                'selling_external' => $request->boolean('selling_external'),
                'selling_our_share_percent' => $data['selling_our_share_percent'] ?? 100,
                'selling_external_agency' => $data['selling_external_agency'] ?? null,
                'offer_date' => $data['offer_date'],
            ]);

            // Re-attach agents (V1 pattern: detach all, re-attach from form)
            $deal->agents()->detach();

            foreach (['listing', 'selling'] as $side) {
                if ($request->boolean($side . '_external')) {
                    continue;
                }

                $agentIds = $request->input($side . '_agents', []);
                $overrides = $request->input($side . '_override', []);

                if (empty($agentIds)) {
                    continue;
                }

                // Validate overrides if any are set
                $anyOverride = false;
                foreach ($agentIds as $id) {
                    $v = $overrides[$id] ?? null;
                    if ($v !== null && $v !== '') {
                        $anyOverride = true;
                        break;
                    }
                }

                if ($anyOverride) {
                    $sum = 0;
                    foreach ($agentIds as $id) {
                        $v = $overrides[$id] ?? null;
                        if ($v === null || $v === '') {
                            return back()->withErrors(ucfirst($side) . ": all agents need a % if any has one.")->withInput();
                        }
                        $sum += (float) $v;
                    }
                    if (abs($sum - 100) > 0.01) {
                        return back()->withErrors(ucfirst($side) . " percentages must total 100. Currently: {$sum}")->withInput();
                    }
                }

                $count = max(count($agentIds), 1);
                $autoSplit = 100.0 / $count;

                foreach ($agentIds as $userId) {
                    $user = User::find($userId);
                    $defaultCut = ($user && $user->agent_cut_percent !== null) ? (float) $user->agent_cut_percent : 50;
                    $defaultPayeMethod = ($user && $user->paye_method) ? $user->paye_method : 'percentage';
                    $defaultPayeValue = ($user && $user->paye_value !== null) ? (float) $user->paye_value : 0;

                    $split = $anyOverride ? (float) ($overrides[$userId] ?? 0) : $autoSplit;

                    $deal->agents()->attach($userId, [
                        'side' => $side,
                        'agent_split_percent' => $split,
                        'agent_cut_percent' => $defaultCut,
                        'paye_method' => $defaultPayeMethod,
                        'paye_value' => $defaultPayeValue,
                    ]);
                }
            }
        }

        // Process step date overrides
        if ($request->has('step_dates')) {
            $svc = app(\App\Services\DealV2\DealPipelineService::class);
            foreach ($request->input('step_dates', []) as $stepId => $newDate) {
                $stepInst = \App\Models\DealV2\DealStepInstance::find($stepId);
                if ($stepInst && $stepInst->deal_id === $deal->id && $stepInst->status !== 'completed' && $newDate) {
                    $stepInst->update([
                        'due_date' => $newDate,
                        'current_rag' => $svc->calculateRag($stepInst, $newDate),
                    ]);
                }
            }
            $svc->recalculateExpectedRegistration($deal);
        }

        // Log update
        \App\Models\DealV2\DealActivityLog::create([
            'deal_id' => $deal->id,
            'user_id' => auth()->id(),
            'action' => 'deal_updated',
            'description' => 'Deal updated by ' . auth()->user()->name,
            'created_at' => now(),
        ]);

        return redirect()->route('deals-v2.show', $deal)->with('status', 'Deal updated.');
    }

    public function destroy(DealV2 $deal)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.archive'), 403);

        if ($deal->isFinanciallyLocked()) {
            return back()->with('error', 'Cannot delete a Paid deal.');
        }

        $deal->delete();

        return redirect()->route('deals-v2.index')->with('status', "Deal {$deal->reference} archived.");
    }

    /**
     * Build agents array from V1-style form inputs (listing_agents[], selling_agents[], overrides).
     */
    private function buildAgentsFromForm(Request $request): array
    {
        $agents = [];

        foreach (['listing', 'selling'] as $side) {
            $ids = $request->input($side . '_agents', []);
            $overrides = $request->input($side . '_override', []);
            $count = max(count($ids), 1);
            $autoSplit = 100.0 / $count;

            $anyOverride = false;
            foreach ($ids as $id) {
                $v = $overrides[$id] ?? null;
                if ($v !== null && $v !== '') {
                    $anyOverride = true;
                    break;
                }
            }

            foreach ($ids as $userId) {
                $split = $anyOverride ? (float) ($overrides[$userId] ?? 0) : $autoSplit;
                $agents[] = [
                    'user_id' => $userId,
                    'side' => $side,
                    'split_percent' => $split,
                ];
            }
        }

        return $agents;
    }
}
