@extends('layouts.corex')

{{--
    .ai/specs/leases.md §15.2 / §15.3 (Build L2) — the ONE capture screen for a new lease (mode=new) and a
    renewal (mode=renew). A lease takes a Property (owner already known), adds Tenant(s) (N-party, never
    assumed 1-2), carries terms, and — only when the agency has linked its own lease agreement — the extra
    agreement details that agreement needs. The Property field is a type-to-search picker (§7.2). After a
    validation error every field comes back from old() (§7.3).

    Two buttons (R1): "Create lease only" (a) and "Create lease & prepare for signing" (b). (b) is unavailable,
    with the reason and where to fix it, until the agency has a linked, ready lease agreement (R3).
--}}

@section('content')
{{-- leases.md §15.9 (Build L3c) — `mode=confirm`: the same screen opened on the lease's own record, showing the lease's value beside the agreement's. --}}
@if(($mode ?? null) === 'confirm')
    @include('corex.leases._agreement-confirm')
@else
@php
    $isRenew = $mode === 'renew';
    $propertyPickerConfig = [
        'active' => !$isRenew && !$property,
        'searchUrl' => route('corex.leases.search-rental-properties'),
        'id' => ($oldProperty ?? null)?->id,
        'label' => ($oldProperty ?? null)?->buildDisplayAddress() ?? '',
    ];

    // Term defaults: what the agent just typed (old()), else — renewal only — the previous term's value.
    $dflt = $renewalBase ?? [];
    // Johan, QA1, 2026-10-07 — from an approved application: the PROPERTY's rent (never the approved amount) and
    // the approved deposit terms applied to it (LeaseController::applicationCaptureDefaults()).
    $appDefaults = $applicationDefaults ?? [];
    $rentValue = old('rental_amount', $dflt['rental_amount'] ?? ($appDefaults['rental_amount'] ?? null));
    $depositValue = old('deposit_amount', $dflt['deposit_amount'] ?? ($appDefaults['deposit_amount'] ?? null));
    $depositTypedOrCarried = old('deposit_amount') !== null || ($dflt['deposit_amount'] ?? null) !== null;
    $startValue = old('start_date', $dflt['start_date'] ?? null);
    $leaseTypeValue = old('lease_type', $dflt['lease_type'] ?? null);

    $agreementCount = count($agreements);
    $agreementReady = $agreementState === 'ready';
    $requiredByAgreement = [];
    foreach ($agreements as $a) {
        $requiredByAgreement[$a['id']] = collect($a['fields'])->where('required', true)
            ->map(fn ($f) => ['key' => $f['key'], 'label' => $f['label']])->values()->all();
    }

    $captureCfg = [
        'agreementId' => $selectedAgreementId,
        'required' => $requiredByAgreement,
        'values' => collect($agreementValues)->map(fn ($v) => $v === null ? '' : (string) $v)->all(),
        'depositMonths' => (float) ($appDefaults['deposit_months'] ?? $depositMonths),
        'depositTouched' => $depositTypedOrCarried,
        'approvedRent' => $appDefaults['approved_rental_amount'] ?? null,
        'rentMode' => $appDefaults['mode'] ?? 'warn',
        'rentNow' => $rentValue !== null && $rentValue !== '' ? (float) $rentValue : null,
        'activate' => (bool) old('activate_immediately'),
        'monthToMonth' => (bool) old('is_month_to_month'),
        'propertyStatus' => $property ? $property->statusBadge() : (($oldProperty ?? null)?->statusBadge() ?? ''),
        'showPaper' => old('intent') === 'paper_copy',
        // leases.md §15.3 (Build L3a) — the landlord panel and per-tenant hints.
        'partyUrl' => route('corex.leases.party-check'),
        'signersOn' => $canPrepare && $agreementReady,
        'fixedPropertyId' => $isRenew ? $lease->property_id : null,
        'fixedTenantIds' => $isRenew ? $lease->tenants->sortByDesc('is_primary')->pluck('contact_id')->filter()->values()->all() : [],
        // leases.md §17 — once the agent has chosen an owner's agent themselves (or the form came back with one), picking
        // a different property no longer overwrites it.
        'ownerAgentTouched' => $isRenew || old('owner_agent_user_id') !== null,
    ];

    $settingsLink = route('corex.rental-lease-templates.index');
    $signLabel = $isRenew ? 'Renew lease & prepare for signing' : 'Create lease & prepare for signing';
    $onlyLabel = $isRenew ? 'Renew lease only' : 'Create lease only';
@endphp
<div class="p-6 max-w-2xl mx-auto space-y-4"
     x-data="leaseCaptureForm('{{ route('corex.properties.contacts.search-global') }}', {{ \Illuminate\Support\Js::from($oldTenants ?? []) }}, {{ \Illuminate\Support\Js::from($propertyPickerConfig) }}, {{ \Illuminate\Support\Js::from($captureCfg) }})"
     x-init="refreshSigners()">
    <div>
        <h1 class="text-lg font-semibold">{{ $isRenew ? 'Renew this lease' : 'New Lease' }}</h1>
        @if($isRenew)
            <p class="text-sm" style="color: var(--text-muted);">{{ $lease->property?->buildDisplayAddress() }} — {{ $lease->tenantNames() }}</p>
        @endif
    </div>

    <form method="POST" enctype="multipart/form-data"
          action="{{ $isRenew ? route('corex.leases.renewal.store', $lease) : route('corex.leases.store') }}"
          @submit="guardSubmit($event)" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        <input type="hidden" name="capture_key" value="{{ $captureKey }}">

        @if ($errors->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(session('capture_gaps'))
            {{-- "Create lease & prepare for signing" found something missing: nothing was created; each item links to where it is fixed. --}}
            <div class="text-xs p-2 rounded space-y-1" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" data-qa="capture-gaps">
                <p class="font-medium">Still needed before the agreement can be prepared:</p>
                <ul class="list-disc pl-4">
                    @foreach(session('capture_gaps') as $gap)
                        <li>{{ $gap['label'] }}@if(!empty($gap['fix_url'])) — <a href="{{ $gap['fix_url'] }}" class="underline" target="_blank" rel="noopener">fix it</a>@endif</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($isRenew)
            {{-- Renewal: the property and the tenants are the lease being renewed — shown, never typed (R7). --}}
            <div>
                <label class="prop-label">Property</label>
                <div class="text-sm mt-1">{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}</div>
            </div>

            <div class="space-y-2">
                <label class="prop-label">Tenant(s)</label>
                <div class="space-y-1">
                    @forelse($lease->tenants as $t)
                        <div class="text-sm rounded px-2 py-1" style="background: var(--surface-2);">{{ $t->contact?->full_name ?? 'Unknown contact' }}{{ $t->is_primary ? ' (primary)' : '' }}</div>
                    @empty
                        <div class="text-sm" style="color: var(--text-muted);">No tenant linked</div>
                    @endforelse
                </div>
                <div class="rounded-md p-3 text-xs space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);" data-qa="renewal-tenant-panel">
                    <p>A renewal keeps the same tenants. If a tenant is leaving or a new tenant is joining, that is a new lease, not a renewal.</p>
                    <a href="{{ route('corex.leases.create', ['property_id' => $lease->property_id]) }}" class="corex-btn-outline text-xs">Start a new lease for this property</a>
                    <p>The current lease must be ended before the new one can be made active.</p>
                </div>
            </div>
        @else
            <div @click.outside="closePropertyResults()">
                <label for="lease-property-search" class="prop-label">Property</label>
                @if($property)
                    <input type="hidden" name="property_id" value="{{ $property->id }}">
                    <div class="text-sm mt-1">{{ $property->buildDisplayAddress() }}</div>
                @else
                    {{-- .ai/specs/leases.md §7.2 — same type-to-search pattern as the rental
                         application / work order property pickers. The hidden input carries the
                         id; editing the text clears it, so the box and the id can never disagree. --}}
                    <input type="text" id="lease-property-search" x-ref="propertySearch" x-model="propertyQuery" autocomplete="off"
                           role="combobox" aria-autocomplete="list" aria-controls="lease-property-results"
                           :aria-expanded="propertyOpen ? 'true' : 'false'"
                           placeholder="Search by address, property name or reference…"
                           @input="propertyEdited()" @input.debounce.300ms="searchProperties()"
                           @focus="reopenPropertyResults()"
                           @keydown.down.prevent="moveHighlight(1)" @keydown.up.prevent="moveHighlight(-1)"
                           @keydown.enter="chooseHighlighted($event)" @keydown.escape="closePropertyResults()"
                           class="prop-input mt-1">
                    <input type="hidden" name="property_id" :value="propertyId">
                    <div id="lease-property-results" role="listbox" x-show="propertyOpen && propertyResults.length" x-cloak
                         class="mt-1 rounded-md max-h-72 overflow-y-auto" style="border: 1px solid var(--border); background: var(--surface);">
                        <template x-for="(p, idx) in propertyResults" :key="p.id">
                            <button type="button" role="option" :aria-selected="highlighted === idx ? 'true' : 'false'"
                                    @click="selectProperty(p)" @mouseenter="highlighted = idx"
                                    :class="highlighted === idx ? 'bg-slate-100' : ''"
                                    class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50">
                                <div class="flex items-center gap-1.5">
                                    <span x-text="p.label"></span>
                                    <span x-show="p.status" x-text="p.status" class="text-[10px] px-1 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); white-space: nowrap;"></span>
                                </div>
                                <div class="text-xs" style="color: var(--text-muted);" x-text="[p.ref ? ('Ref: ' + p.ref) : '', p.agent].filter(Boolean).join(' · ')"></div>
                            </button>
                        </template>
                    </div>
                    <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="propertySearching" x-cloak>Searching…</p>
                    <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="propertySearched && !propertySearching && propertyResults.length === 0 && !propertyId" x-cloak
                       x-text="'No rental properties match ' + propertyQuery.trim() + '. Try the street, suburb, property name or reference.'"></p>
                    <p class="text-xs mt-1" style="color: var(--ds-crimson);" x-show="propertyError" x-cloak x-text="propertyError"></p>
                    <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="propertyId" x-cloak>Property selected.</p>
                    <p class="text-xs mt-1" style="color: var(--ds-crimson);" x-show="propertyAttempted && !propertyId" x-cloak>Choose a property from the list.</p>
                @endif
            </div>

            @if($rentalApplication)
                <input type="hidden" name="rental_application_id" value="{{ $rentalApplication->id }}">
                <p class="text-xs" style="color: var(--text-muted);">Linked to rental application #{{ $rentalApplication->id }}.</p>
            @endif

            <div>
                <label class="prop-label">Tenant(s)</label>
                <p class="text-xs mb-1" style="color: var(--text-muted);">Add one or more — a lease can have joint tenants.</p>
                <input type="text" x-model="query" @input.debounce.300ms="search()" placeholder="Search contacts by name, phone, or email…"
                       class="prop-input">
                <div class="mt-1 rounded-md" style="border: 1px solid var(--border);" x-show="results.length > 0" x-cloak>
                    <template x-for="r in results" :key="r.id">
                        <button type="button" @click="add(r)" class="block w-full text-left px-3 py-2 text-sm" style="border-bottom: 1px solid var(--border);" x-text="r.name + (r.email ? ' — ' + r.email : '')"></button>
                    </template>
                </div>
                <div class="mt-2 space-y-1">
                    <template x-for="(t, idx) in selected" :key="t.id">
                        <div class="flex items-center justify-between text-sm rounded px-2 py-1" style="background: var(--surface-2);">
                            <span x-text="t.name + (idx === 0 ? ' (primary)' : '')"></span>
                            <button type="button" @click="remove(idx)" class="text-xs" style="color: var(--ds-crimson);">Remove</button>
                        </div>
                    </template>
                </div>
                <template x-for="t in selected" :key="'input-' + t.id">
                    <input type="hidden" name="tenant_contact_ids[]" :value="t.id">
                </template>
                <p class="text-xs mt-1" style="color: var(--ds-crimson);" x-show="submitAttempted && selected.length === 0" x-cloak>At least one tenant is required.</p>
            </div>
        @endif

        {{-- Term — constant two-column grid, per-field spans (leases.md §6a.i). --}}
        <div class="grid grid-cols-2 gap-3">
            <div class="col-span-2 sm:col-span-1">
                <label class="prop-label">Monthly rental (R)</label>
                <input type="number" name="rental_amount" value="{{ $rentValue }}" step="0.01" min="0" required x-ref="rent" @input="rentChanged()" class="prop-input">
                @if($appDefaults && ($appDefaults['rental_amount'] ?? null) !== null && old('rental_amount') === null)
                    <p class="text-[11px] mt-0.5" style="color: var(--text-muted);">From the property's rent.</p>
                @elseif($appDefaults && ($appDefaults['rental_amount'] ?? null) === null && old('rental_amount') === null)
                    <p class="text-[11px] mt-0.5" style="color: var(--text-muted);">The property has no rent on record — enter it.</p>
                @endif
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="prop-label">Deposit (R)</label>
                <input type="number" name="deposit_amount" value="{{ $depositValue }}" step="0.01" min="0" x-ref="deposit" @input="depositTouched = true" class="prop-input">
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="prop-label">Start date</label>
                <input type="date" name="start_date" value="{{ $startValue }}" required class="prop-input" style="color-scheme: light dark;">
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="prop-label">End date</label>
                <input type="date" name="end_date" value="{{ old('end_date') }}" x-bind:disabled="monthToMonth" class="prop-input" style="color-scheme: light dark;">
            </div>
        </div>

        {{-- leases.md §17 (Johan, 8 Oct 2026) — the two agents on the lease. The owner's agent starts as the property's agent
             (it follows the property picked here until the agent chooses one); the tenant's agent starts as the agent who sent
             out the application (else who processed it, else you). A renewal carries both forward. The portal links show them. --}}
        <div class="grid grid-cols-2 gap-3" data-qa="lease-agents-fields">
            <div class="col-span-2 sm:col-span-1">
                @include('corex.leases._agent-select', [
                    'name' => 'owner_agent_user_id', 'label' => "Owner's agent",
                    'selected' => old('owner_agent_user_id', $agentDefaults['owner'] ?? null),
                    'agentOptions' => $agentOptions, 'required' => false,
                    'attrs' => 'x-ref="ownerAgent" @change="ownerAgentTouched = true"',
                    'help' => 'Looks after the landlord. Shown on the landlord\'s portal link.',
                ])
            </div>
            <div class="col-span-2 sm:col-span-1">
                @include('corex.leases._agent-select', [
                    'name' => 'tenant_agent_user_id', 'label' => "Tenant's agent",
                    'selected' => old('tenant_agent_user_id', $agentDefaults['tenant'] ?? null),
                    'agentOptions' => $agentOptions, 'required' => false,
                    'help' => 'Looks after the tenant. Shown on the tenant\'s portal link.',
                ])
            </div>
        </div>

        {{-- Johan, QA1, 2026-10-07 — lease rent above the amount this tenant was approved for. Server twin:
             RentalApplication::rentAboveApproved() via LeaseCaptureRequest (refuses / demands the reason regardless of this UI). --}}
        @if($rentalApplication && $rentalApplication->approved_rental_amount !== null)
            <div class="rounded-md px-3 py-2 text-xs" x-show="rentOver !== null" x-cloak data-test="rent-above-approved"
                 style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #92400e); border: 1px solid var(--ds-amber, #f59e0b);">
                <div>
                    This tenant was approved for <strong x-text="money(approvedRent)"></strong> a month; this lease is
                    <strong x-text="money(rentNow)"></strong> — <strong x-text="money(rentOver)"></strong> above.
                    <span x-show="rentMode === 'block'">Your agency does not allow a lease above the approved amount.</span>
                </div>
                <div class="mt-1" x-show="rentMode !== 'block'">
                    <label class="prop-label">Reason for leasing above the approved amount (required, logged)</label>
                    <input type="text" name="rent_above_approved_reason" maxlength="1000" value="{{ old('rent_above_approved_reason') }}"
                           :required="rentOver !== null && rentMode !== 'block'" class="prop-input">
                </div>
            </div>
            @error('rent_above_approved_reason')<p class="text-xs" style="color: var(--ds-crimson);">{{ $message }}</p>@enderror
        @endif

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_month_to_month" value="1" @checked(old('is_month_to_month')) x-model="monthToMonth">
            Month-to-month (no fixed end date)
        </label>

        @if($showLeaseType)
            <div>
                <label class="prop-label">Lease type</label>
                <select name="lease_type" class="prop-select">
                    <option value="">—</option>
                    {{-- .ai/specs/rental-property-tab.md §5, Part 4 — agency-editable
                         list, same source as the property screen's Lease Type select. --}}
                    @foreach($leaseTypes ?? [] as $lt)
                        <option value="{{ $lt->name }}" @selected($leaseTypeValue === $lt->name)>{{ $lt->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if($agreementReady)
            @include('corex.leases._agreement-fields')
        @endif

        {{-- leases.md §18 — notice and early-cancellation terms: always asked (a linked agreement or not); they start from the
             agency's defaults (or the term being renewed) and are what the lease agreement and the portal FAQ are filled from. --}}
        <div class="space-y-2" data-qa="capture-notice-terms">
            <h3 class="text-sm font-semibold">Notice and early cancellation <span class="text-xs font-normal" style="color: var(--text-muted);">· {{ $noticeDefaultsNote ?? 'From your agency defaults' }}</span></h3>
            @include('corex.leases._notice-terms', [
                'noticeValues' => $noticeValues ?? [],
                'noticePrefix' => 'notice',
                'noticeHide' => in_array('earliest_termination_date', $noticeAgreementKeys ?? [], true) ? ['earliest_termination_date'] : [],
                'earliestNoticeMonths' => $earliestNoticeMonths ?? null,
            ])
        </div>

        @if($agreementReady)
            @include('corex.leases._signers-panel')
            @include('corex.leases._signing-checklist')
        @endif

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="activate_immediately" value="1" @checked(old('activate_immediately')) x-model="activate">
            {{ $isRenew ? 'Activate immediately' : 'Activate immediately (skip draft)' }}
        </label>

        {{-- Signed paper copy (R2) — available on a new lease and on a renewal, with or without a linked lease agreement. --}}
        <div class="space-y-2">
            <button type="button" @click="showPaper = !showPaper" class="text-xs underline" style="color: var(--text-secondary);">I already have the signed copy — attach it</button>
            <div x-show="showPaper" x-cloak class="rounded-md p-3 space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);" data-qa="paper-copy-panel">
                <label class="prop-label">Signed copy</label>
                <input type="file" name="signed_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" x-bind:disabled="!showPaper" class="w-full text-sm">
                <button type="submit" name="intent" value="paper_copy" @click="submitAttempted = true" class="corex-btn-primary text-xs">{{ $isRenew ? 'Renew with the signed copy' : 'Create lease with the signed copy' }}</button>
            </div>
        </div>

        <div class="space-y-2 pt-2">
            <div class="flex flex-wrap justify-end gap-2">
                <a href="{{ $isRenew ? route('corex.leases.show', $lease) : route('corex.leases.index') }}" class="corex-btn-outline text-xs">Cancel</a>
                <button type="submit" name="intent" value="lease_only" @click="submitAttempted = true" class="corex-btn-primary text-xs">{{ $onlyLabel }}</button>
                @if($canPrepare)
                    @if($agreementReady)
                        <button type="submit" name="intent" value="lease_and_sign" @click="submitAttempted = true" class="corex-btn-secondary text-xs">{{ $signLabel }}</button>
                    @else
                        <button type="submit" disabled aria-disabled="true" class="corex-btn-secondary text-xs" style="opacity: 0.5; cursor: not-allowed;">{{ $signLabel }}</button>
                    @endif
                @endif
            </div>
            @if($canPrepare && !$agreementReady)
                {{-- R3 — directly beneath the unavailable button: why, and where to fix it. --}}
                <p class="text-xs text-right" style="color: var(--text-muted);" data-qa="prepare-unavailable">
                    @if($agreementState === 'attention')
                        Your lease agreement needs attention{{ $agreementReason ? ' — ' . rtrim($agreementReason, '.') . '.' : '.' }}
                    @else
                        Your agency has not set up a lease agreement yet.
                    @endif
                    @if($canManageAgreements)
                        {{ $agreementState === 'attention' ? 'Fix it under' : 'An administrator sets one up under' }}
                        <a href="{{ $settingsLink }}" class="underline">Settings → Rental lease agreements</a>.
                    @else
                        Ask your agency administrator{{ $agreementState === 'attention' ? ' to fix it under' : ' to set one up under' }} Settings → Rental lease agreements.
                    @endif
                </p>
            @endif
        </div>
    </form>
</div>

<script>
function leaseCaptureForm(searchUrl, seed, propertyCfg, cfg) {
    propertyCfg = propertyCfg || {};
    cfg = cfg || {};
    const startValues = {};
    Object.keys(cfg.values || {}).forEach(function (k) { startValues[k] = cfg.values[k] == null ? '' : String(cfg.values[k]); });
    return {
        query: '',
        results: [],
        selected: seed || [],
        submitAttempted: false,
        // Agreement details (leases.md §15.3): one group of fields per linked lease agreement; only the
        // chosen group is enabled, so only its values are posted.
        agreementId: cfg.agreementId || '',
        requiredByAgreement: cfg.required || {},
        vals: startValues,
        // Term helpers.
        monthToMonth: !!cfg.monthToMonth,
        activate: !!cfg.activate,
        showPaper: !!cfg.showPaper,
        depositMonths: Number(cfg.depositMonths) || 0,
        depositTouched: !!cfg.depositTouched,
        propertyStatus: cfg.propertyStatus || '',
        // Rent above the approved amount (from an approved application only).
        approvedRent: cfg.approvedRent === null || cfg.approvedRent === undefined ? null : Number(cfg.approvedRent),
        rentMode: cfg.rentMode || 'warn',
        rentNow: cfg.rentNow === null || cfg.rentNow === undefined ? null : Number(cfg.rentNow),
        get rentOver() {
            return (this.approvedRent !== null && this.rentNow !== null && !isNaN(this.rentNow) && Math.round(this.rentNow * 100) > Math.round(this.approvedRent * 100))
                ? Math.round((this.rentNow - this.approvedRent) * 100) / 100 : null;
        },
        money(v) { return 'R' + Number(v).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        // Property picker (.ai/specs/leases.md §7.2). propertyId is the single source of
        // truth for what gets posted; propertyQuery is only the text in the box.
        propertyActive: !!propertyCfg.active,
        propertySearchUrl: propertyCfg.searchUrl || '',
        propertyId: propertyCfg.id || '',
        propertyQuery: propertyCfg.label || '',
        propertyResults: [],
        propertyOpen: false,
        propertySearching: false,
        propertySearched: false,
        propertyError: '',
        propertyAttempted: false,
        highlighted: -1,
        propertySeq: 0,
        // The landlord panel / per-tenant hints (leases.md §15.3, Build L3a).
        signersOn: !!cfg.signersOn,
        partyUrl: cfg.partyUrl || '',
        fixedPropertyId: cfg.fixedPropertyId || null,
        fixedTenantIds: cfg.fixedTenantIds || [],
        signers: { loaded: false, landlords: [], tenants: [], landlordMissing: false, landlordUrl: null },
        signersSeq: 0,
        ownerAgentTouched: !!cfg.ownerAgentTouched,
        async refreshSigners() {
            if (!this.signersOn) { return; }
            const propertyId = this.fixedPropertyId || this.propertyId || '';
            const tenantIds = this.fixedTenantIds.length ? this.fixedTenantIds : this.selected.map(function (t) { return t.id; });
            const seq = ++this.signersSeq;
            try {
                const url = new URL(this.partyUrl, window.location.origin);
                if (propertyId) { url.searchParams.set('property_id', propertyId); }
                if (this.agreementId) { url.searchParams.set('agreement_id', this.agreementId); }
                tenantIds.forEach(function (id) { url.searchParams.append('tenant_ids[]', id); });
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                const data = await res.json();
                if (seq !== this.signersSeq) { return; }
                this.signers = {
                    loaded: !!data.property_chosen,
                    landlords: data.landlords || [],
                    tenants: data.tenants || [],
                    landlordMissing: !!data.landlord_missing,
                    landlordUrl: data.landlord_url || null,
                };
            } catch (e) {
                // A failed look-up never blocks the form — the server checks everything again on submit.
            }
        },
        missing() {
            const required = (this.requiredByAgreement || {})[this.agreementId] || [];
            return required.filter(function (f) { return String((this.vals[f.key] === undefined || this.vals[f.key] === null) ? '' : this.vals[f.key]).trim() === ''; }.bind(this));
        },
        rentChanged() {
            const typed = parseFloat(this.$refs.rent ? this.$refs.rent.value : '');
            this.rentNow = isNaN(typed) ? null : typed;
            // The agency's default deposit (months of rent) is only a starting suggestion, never forced:
            // once the agent touches the deposit box it is theirs.
            if (this.depositTouched || this.depositMonths <= 0 || !this.$refs.rent || !this.$refs.deposit) { return; }
            const rent = parseFloat(this.$refs.rent.value);
            this.$refs.deposit.value = isNaN(rent) ? '' : (Math.round(rent * this.depositMonths * 100) / 100).toFixed(2);
        },
        propertyEdited() {
            // Typing after a pick means the pick is no longer what is in the box.
            this.propertyId = '';
            this.propertyStatus = '';
            this.propertyError = '';
            this.refreshSigners();
            this.highlighted = -1;
            this.propertySeq++;
            this.propertySearching = false;
            if (this.propertyQuery.trim().length < 2) {
                this.propertyResults = [];
                this.propertyOpen = false;
                this.propertySearched = false;
            }
        },
        async searchProperties() {
            const term = this.propertyQuery.trim();
            if (term.length < 2 || this.propertyId) { return; }
            const seq = ++this.propertySeq;
            this.propertySearching = true;
            this.propertyError = '';
            try {
                const url = new URL(this.propertySearchUrl, window.location.origin);
                url.searchParams.set('q', term);
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                const data = await res.json();
                if (seq !== this.propertySeq) { return; }
                this.propertyResults = data;
                this.highlighted = data.length ? 0 : -1;
                this.propertyOpen = true;
                this.propertySearched = true;
            } catch (e) {
                if (seq !== this.propertySeq) { return; }
                this.propertyResults = [];
                this.propertyOpen = false;
                this.propertySearched = false;
                this.propertyError = 'Could not search properties just now. Please try again.';
            } finally {
                if (seq === this.propertySeq) { this.propertySearching = false; }
            }
        },
        selectProperty(p) {
            this.propertyId = p.id;
            this.propertyQuery = p.label;
            this.propertyStatus = p.status || '';
            // The letting commission % starts at this property's own, unless the agent already typed one.
            if (Object.prototype.hasOwnProperty.call(this.vals, 'commission_percent') && String(this.vals.commission_percent || '').trim() === '' && p.commission_percent != null) {
                this.vals.commission_percent = String(p.commission_percent);
            }
            // The owner's agent follows the property's agent until the agent picks one themselves (leases.md §17).
            const ownerSelect = this.$refs.ownerAgent;
            if (!this.ownerAgentTouched && ownerSelect && p.agent_id && ownerSelect.querySelector('option[value="' + p.agent_id + '"]')) {
                ownerSelect.value = String(p.agent_id);
            }
            this.refreshSigners();
            this.propertyResults = [];
            this.propertyOpen = false;
            this.propertySearched = false;
            this.propertyAttempted = false;
            this.highlighted = -1;
        },
        moveHighlight(step) {
            const n = this.propertyResults.length;
            if (!n) { return; }
            this.propertyOpen = true;
            this.highlighted = (this.highlighted + step + n) % n;
        },
        chooseHighlighted(e) {
            // Enter picks the highlighted row instead of submitting the form; with no
            // list open it falls through to a normal submit (guardSubmit then explains).
            if (this.propertyOpen && this.propertyResults.length && this.highlighted >= 0) {
                e.preventDefault();
                this.selectProperty(this.propertyResults[this.highlighted]);
            }
        },
        reopenPropertyResults() {
            if (this.propertyResults.length && !this.propertyId) { this.propertyOpen = true; }
        },
        closePropertyResults() {
            this.propertyOpen = false;
        },
        guardSubmit(e) {
            if (this.propertyActive && !this.propertyId) {
                e.preventDefault();
                this.propertyAttempted = true;
                if (this.$refs.propertySearch) { this.$refs.propertySearch.focus(); }
                return;
            }
            if (this.rentOver !== null && this.rentMode === 'block') {
                e.preventDefault();
                return;
            }
            // leases.md §12.5.2 — a lease CAN be made active on a withdrawn property; this is only a speed-bump
            // before the lease goes live (never for "prepare for signing", which does not activate).
            const intent = e.submitter && e.submitter.value ? e.submitter.value : 'lease_only';
            const activating = intent === 'paper_copy' || (intent === 'lease_only' && this.activate);
            if (activating && String(this.propertyStatus).toLowerCase() === 'withdrawn'
                && !window.confirm('This property is withdrawn. Are you sure you want to use it for this lease?')) {
                e.preventDefault();
            }
        },
        async search() {
            if (this.query.trim().length < 2) { this.results = []; return; }
            const excludeIds = this.selected.map(t => t.id);
            const url = new URL(searchUrl, window.location.origin);
            url.searchParams.set('q', this.query);
            excludeIds.forEach(id => url.searchParams.append('exclude[]', id));
            const res = await fetch(url);
            const data = await res.json();
            this.results = data.map(c => ({
                id: c.id,
                name: [c.first_name, c.last_name].filter(Boolean).join(' ') || c.name || ('Contact #' + c.id),
                email: c.email || '',
            }));
        },
        add(contact) {
            if (this.selected.find(t => t.id === contact.id)) return;
            this.selected.push(contact);
            this.query = '';
            this.results = [];
            this.refreshSigners();
        },
        remove(idx) {
            this.selected.splice(idx, 1);
            this.refreshSigners();
        },
    };
}
</script>
@endif
@endsection
