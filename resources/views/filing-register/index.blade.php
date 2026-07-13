{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ showNew: false }">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" data-tour="docs-filing-register-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">
                    Filing Register &mdash; {{ $branchName }}
                </h1>
                <p class="text-sm text-white/60">Searchable index of physically filed mandates.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                @permission('filing.create')
                <button type="button" @click="showNew = !showNew" class="corex-btn-primary text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    New Filing
                </button>
                @endpermission
            </div>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="rounded-md p-4" data-tour="docs-filing-register-filters" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('filing-register.index') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[220px]">
                <label for="search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <input id="search" type="text" name="search" value="{{ request('search') }}"
                       placeholder="Address, reference, seller, seq..."
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="document_type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type</label>
                <select id="document_type" name="document_type" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="All" {{ request('document_type') === 'All' ? 'selected' : '' }}>All</option>
                    <option value="OA" {{ request('document_type') === 'OA' ? 'selected' : '' }}>OA</option>
                    <option value="EA" {{ request('document_type') === 'EA' ? 'selected' : '' }}>EA</option>
                    <option value="Other" {{ request('document_type') === 'Other' ? 'selected' : '' }}>Other</option>
                </select>
            </div>
            <div>
                <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="status" name="status" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="All" {{ request('status') === 'All' ? 'selected' : '' }}>All</option>
                    <option value="Active" {{ request('status') === 'Active' ? 'selected' : '' }}>Active</option>
                    <option value="Expiring" {{ request('status') === 'Expiring' ? 'selected' : '' }}>Expiring Soon</option>
                    <option value="Expired" {{ request('status') === 'Expired' ? 'selected' : '' }}>Expired</option>
                    <option value="Archived" {{ request('status') === 'Archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div>
            @if($isAdmin)
            <div>
                <label for="branch_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch</label>
                <select id="branch_id" name="branch_id" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div>
                <label for="agent_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                <select id="agent_id" name="agent_id" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Agents</option>
                    @foreach($agents as $ag)
                    <option value="{{ $ag->id }}" {{ request('agent_id') == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary">Filter</button>
                @if(request()->hasAny(['search','document_type','status','branch_id','agent_id']))
                    <a href="{{ route('filing-register.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>
                @endif
            </div>
        </form>
    </div>

    {{-- Summary tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-tour="docs-filing-register-tiles">
        <div class="ds-status-card">
            <div class="ds-label">Total Filed</div>
            <div class="ds-value-lg">{{ number_format($totalCount) }}</div>
        </div>
        <div class="ds-status-card" style="border-left-color: var(--ds-green);">
            <div class="ds-label">Active</div>
            <div class="ds-value-lg" style="color: var(--ds-green);">{{ number_format($activeCount) }}</div>
        </div>
        <div class="ds-status-card" style="border-left-color: var(--ds-amber);">
            <div class="ds-label">Expiring (30 days)</div>
            <div class="ds-value-lg" style="color: var(--ds-amber);">{{ number_format($expiringCount) }}</div>
        </div>
        <div class="ds-status-card" style="border-left-color: var(--ds-amber);">
            <div class="ds-label">Expired</div>
            <div class="ds-value-lg" style="color: var(--ds-amber);">{{ number_format($expiredCount) }}</div>
        </div>
    </div>

    {{-- Add new filing (opened from the header "New Filing" button) --}}
    @permission('filing.create')
    <div x-show="showNew" x-cloak x-transition class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">New Filing</h2>
        <form method="POST" action="{{ route('filing-register.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4"
              x-data="filingPicker({})">
            @csrf
            {{-- ── STEP 1. THE PROPERTY. Johan's flow: the property is the LEAD selector, and
                 everything the system can already answer answers itself from it — branch, agent,
                 seller, mandate expiry. The clerk is left with the one fact CoreX cannot know:
                 the file number. Free text remains a first-class path for a filing whose
                 property CoreX does not hold. ── --}}
            {{-- AT-238 — Property: search the property tables, link the real record.
                 The typed text is ALWAYS submitted as property_address, so a filing whose
                 property CoreX does not hold still saves. Linking is an upgrade, never a gate. --}}
            <div class="relative">
                <label for="new_property_address" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property <span class="text-red-500">*</span></label>
                <input type="hidden" name="property_id" :value="propertyId">
                <input id="new_property_address" type="text" name="property_address" required tabindex="1"
                       autocomplete="off"
                       placeholder="Search a property, or just type the address"
                       x-model="address" @input="onType()" @focus="onType()" @blur="closeSoon()"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       :style="propertyId
                            ? 'background: var(--surface); border: 1px solid var(--ds-green, #059669); color: var(--text-primary);'
                            : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">

                <div x-show="open && results.length" x-cloak
                     class="absolute z-30 left-0 right-0 mt-1 rounded-md overflow-hidden"
                     style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,.18); max-height: 260px; overflow-y: auto;">
                    <template x-for="r in results" :key="r.id">
                        <button type="button" @mousedown.prevent="pick(r)"
                                class="w-full text-left px-3 py-2 text-sm hover:opacity-80"
                                style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
                            <span x-text="r.address || r.label"></span>
                            <span class="block text-xs" style="color: var(--text-muted);">
                                <span x-text="r.agent ? ('Agent: ' + r.agent) : ''"></span>
                                <span x-show="r.expiry_date" x-text="' · mandate expires ' + r.expiry_date"></span>
                            </span>
                        </button>
                    </template>
                </div>

                <div x-show="propertyId" x-cloak class="text-xs mt-1 flex items-center gap-2" style="color: var(--ds-green, #059669);">
                    <span>✓ Linked to the property record</span>
                    <button type="button" @click="unlink()" class="underline" style="color: var(--text-muted);">unlink</button>
                </div>
                <p x-show="!propertyId" x-cloak class="text-xs mt-1" style="color: var(--text-muted);">
                    No CoreX match? Type the address — the filing still saves.
                </p>
            </div>

            {{-- AT-238 (Johan's flow) — Branch and Agent are DERIVED from the property's own
                 listing context the moment one is picked. They stay editable (a filing can be
                 booked under another branch/agent), but the clerk is never asked to know them.
                 With no property linked they fall back to the clerk's own branch/agent. --}}
            <div>
                <label for="new_branch_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Branch
                    <span x-show="derivedFrom.branch" x-cloak class="ds-badge ds-badge-success" style="margin-left:.3rem;">from property</span>
                </label>
                <select id="new_branch_id" name="branch_id" x-model="branchId" tabindex="5" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">— use my branch —</option>
                    @foreach($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="new_agent_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Agent
                    <span x-show="derivedFrom.agent" x-cloak class="ds-badge ds-badge-success" style="margin-left:.3rem;">from property</span>
                </label>
                <select id="new_agent_id" name="agent_id" x-model="agentId" tabindex="6" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">— me —</option>
                    @foreach($agents as $ag)
                    <option value="{{ $ag->id }}">{{ $ag->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="new_document_type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type <span class="text-red-500">*</span></label>
                <select id="new_document_type" name="document_type" required tabindex="7" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="OA">OA (Open Authority)</option>
                    <option value="EA">EA (Exclusive Authority)</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div>
                <label for="new_file_reference" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">File Reference <span class="text-red-500">*</span></label>
                <input id="new_file_reference" type="text" name="file_reference" required tabindex="3" placeholder="e.g. File 3"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="new_sequence_number" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sequence Number <span class="text-red-500">*</span></label>
                <input id="new_sequence_number" type="text" name="sequence_number" required tabindex="4" placeholder="e.g. 0042"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            {{-- AT-238 — Seller: sourced from the property's link roles when we know them. --}}
            <div>
                <label for="new_seller_name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Seller</label>
                <input type="hidden" name="seller_contact_id" :value="sellerContactId">
                <select x-show="sellers.length" x-cloak
                        @change="pickSeller($event.target.value)"
                        class="w-full rounded-md px-3 py-2 text-sm mb-1"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">— type a name instead —</option>
                    <template x-for="s in sellers" :key="s.id">
                        <option :value="s.id" :selected="s.id == sellerContactId" x-text="s.name"></option>
                    </template>
                </select>
                <input id="new_seller_name" type="text" name="seller_name" tabindex="8" placeholder="Optional"
                       x-model="sellerName"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            {{-- AT-238 — Expiry: prefilled from the property's mandate on link, then it is YOURS.
                 It is stored on this row, never mirrored: an OA and an EA on the same property
                 can genuinely expire on different dates, and the register records what was filed. --}}
            <div>
                <label for="new_expiry_date" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Expiry Date</label>
                <input id="new_expiry_date" type="date" name="expiry_date" tabindex="8"
                       x-model="expiry"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <p x-show="suggestedExpiry && suggestedExpiry !== expiry" x-cloak class="text-xs mt-1" style="color: var(--text-muted);">
                    Property mandate expires <span x-text="suggestedExpiry"></span> —
                    <button type="button" class="underline" @click="expiry = suggestedExpiry" style="color: var(--brand-icon, #0ea5e9);">use it</button>
                </p>
            </div>
            <div>
                <label for="new_notes" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                <input id="new_notes" type="text" name="notes" tabindex="9" placeholder="Optional"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="md:col-span-3 flex items-center gap-2">
                <button type="submit" tabindex="10" class="corex-btn-primary">Save Filing</button>
                <button type="button" @click="showNew = false" class="corex-btn-outline">Cancel</button>
            </div>
        </form>
    </div>
    @endpermission

    {{-- Main table --}}
    <div class="rounded-md overflow-hidden" data-tour="docs-filing-register-table" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table table-sticky">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property Address</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Seller</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiry</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($filings as $filing)
                    {{-- One row: display cells (x-show="!editing") + a single full-width edit cell (x-show="editing").
                         Cells are server-rendered so they never pop/shift on Alpine hydration. --}}
                    <tr x-data="{ editing: false, ...filingPicker({
                            propertyId: {{ $filing->property_id ?: 'null' }},
                            address: @js($filing->property_address),
                            sellerContactId: {{ $filing->seller_contact_id ?: 'null' }},
                            sellerName: @js($filing->seller_name),
                            expiry: @js($filing->expiry_date ? $filing->expiry_date->format('Y-m-d') : ''),
                        }) }" style="border-top: 1px solid var(--border);">
                        {{-- Display cells --}}
                        <td x-show="!editing" class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $filing->full_reference }}</td>
                        <td x-show="!editing" class="px-4 py-3">
                            @if($filing->document_type === 'OA')
                                <span class="ds-badge ds-badge-info">OA</span>
                            @elseif($filing->document_type === 'EA')
                                <span class="ds-badge ds-badge-info">EA</span>
                            @else
                                <span class="ds-badge ds-badge-default">Other</span>
                            @endif
                        </td>
                        {{-- AT-238 — show the LINKED record when there is one (the register stops
                             disagreeing with the property page), the typed text when there isn't. --}}
                        <td x-show="!editing" class="px-4 py-3">
                            @if($filing->property)
                                <a href="{{ route('corex.properties.show', $filing->property) }}"
                                   class="no-underline hover:underline" style="color: var(--text-primary);">{{ $filing->property_display }}</a>
                                <span class="ds-badge ds-badge-success" style="margin-left:.35rem;"
                                      title="Linked to the property record — address, seller and mandate expiry come from the property, not retyped.">Linked</span>
                            @else
                                {{ $filing->property_address }}
                            @endif
                        </td>
                        <td x-show="!editing" class="px-4 py-3" style="color: var(--text-secondary);">
                            @if($filing->sellerContact)
                                <a href="{{ route('corex.contacts.show', $filing->sellerContact) }}"
                                   class="no-underline hover:underline" style="color: var(--text-secondary);">{{ $filing->seller_display }}</a>
                            @else
                                {{ $filing->seller_display ?? '—' }}
                            @endif
                        </td>
                        <td x-show="!editing" class="px-4 py-3">{{ $filing->agent->name ?? '—' }}</td>
                        <td x-show="!editing" class="px-4 py-3 whitespace-nowrap">
                            {{ $filing->expiry_date ? $filing->expiry_date->format('Y-m-d') : '—' }}
                        </td>
                        <td x-show="!editing" class="px-4 py-3">
                            @if($showArchived)
                                <span class="ds-badge ds-badge-warning">Archived</span>
                            @elseif($filing->status === 'active')
                                <span class="ds-badge ds-badge-success">Active</span>
                            @elseif($filing->status === 'expiring')
                                <span class="ds-badge ds-badge-warning">Expiring</span>
                            @else
                                <span class="ds-badge ds-badge-warning">Expired</span>
                            @endif
                        </td>
                        <td x-show="!editing" class="px-4 py-3 text-right whitespace-nowrap">
                            @if($showArchived)
                                <form method="POST" action="{{ route('filing-register.restore', $filing->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold" style="color: var(--ds-green);">Restore</button>
                                </form>
                            @else
                                @permission('filing.edit')
                                <button @click="editing = true" class="text-xs font-semibold mr-3" style="color: var(--brand-icon);">Edit</button>
                                @endpermission
                                @permission('filing.archive')
                                <form method="POST" action="{{ route('filing-register.destroy', $filing->id) }}" class="inline" onsubmit="return confirm('Delete this filing entry?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                                </form>
                                @endpermission
                            @endif
                        </td>

                        {{-- Inline edit cell (hidden until Edit is clicked) --}}
                        <td x-show="editing" x-cloak colspan="8" class="px-4 py-3">
                            <form method="POST" action="{{ route('filing-register.update', $filing->id) }}" class="flex flex-wrap items-end gap-3">
                                @csrf @method('PUT')
                                <input type="hidden" name="branch_id" value="{{ $filing->branch_id }}">
                                <div>
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                                    <select name="agent_id" class="rounded-md px-2 py-1 text-xs"
                                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        @foreach($agents as $ag)
                                        <option value="{{ $ag->id }}" {{ $filing->agent_id == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">Type</label>
                                    <select name="document_type" class="rounded-md px-2 py-1 text-xs"
                                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        <option value="OA" {{ $filing->document_type === 'OA' ? 'selected' : '' }}>OA</option>
                                        <option value="EA" {{ $filing->document_type === 'EA' ? 'selected' : '' }}>EA</option>
                                        <option value="Other" {{ $filing->document_type === 'Other' ? 'selected' : '' }}>Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">File Ref</label>
                                    <input type="text" name="file_reference" value="{{ $filing->file_reference }}"
                                           class="rounded-md px-2 py-1 text-xs w-20"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">Seq #</label>
                                    <input type="text" name="sequence_number" value="{{ $filing->sequence_number }}"
                                           class="rounded-md px-2 py-1 text-xs w-16"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                {{-- AT-238 — same picker as the new-filing form, seeded with this row's
                                     current link. Editing an old free-text row is how it gets linked. --}}
                                <div class="relative">
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">Property</label>
                                    <input type="hidden" name="property_id" :value="propertyId">
                                    <input type="text" name="property_address" autocomplete="off"
                                           x-model="address" @input="onType()" @focus="onType()" @blur="closeSoon()"
                                           class="rounded-md px-2 py-1 text-xs w-40"
                                           :style="propertyId
                                                ? 'background: var(--surface); border: 1px solid var(--ds-green, #059669); color: var(--text-primary);'
                                                : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">
                                    <div x-show="open && results.length" x-cloak
                                         class="absolute z-30 left-0 mt-1 rounded-md"
                                         style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,.18); min-width: 260px; max-height: 220px; overflow-y: auto;">
                                        <template x-for="r in results" :key="r.id">
                                            <button type="button" @mousedown.prevent="pick(r)"
                                                    class="w-full text-left px-3 py-2 text-xs"
                                                    style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
                                                <span x-text="r.address || r.label"></span>
                                            </button>
                                        </template>
                                    </div>
                                    <div x-show="propertyId" x-cloak class="text-[0.6875rem] mt-1" style="color: var(--ds-green, #059669);">
                                        ✓ linked · <button type="button" @click="unlink()" class="underline" style="color: var(--text-muted);">unlink</button>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">Seller</label>
                                    <input type="hidden" name="seller_contact_id" :value="sellerContactId">
                                    <select x-show="sellers.length" x-cloak @change="pickSeller($event.target.value)"
                                            class="rounded-md px-2 py-1 text-xs w-28 mb-1"
                                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        <option value="">— type a name —</option>
                                        <template x-for="s in sellers" :key="s.id">
                                            <option :value="s.id" :selected="s.id == sellerContactId" x-text="s.name"></option>
                                        </template>
                                    </select>
                                    <input type="text" name="seller_name" x-model="sellerName"
                                           class="rounded-md px-2 py-1 text-xs w-28"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">Expiry</label>
                                    <input type="date" name="expiry_date" x-model="expiry"
                                           class="rounded-md px-2 py-1 text-xs"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    {{-- Never rewrites what was filed — it offers, the human decides. --}}
                                    <p x-show="suggestedExpiry && suggestedExpiry !== expiry" x-cloak class="text-[0.6875rem] mt-1" style="color: var(--text-muted);">
                                        mandate: <span x-text="suggestedExpiry"></span>
                                        <button type="button" class="underline" @click="expiry = suggestedExpiry" style="color: var(--brand-icon, #0ea5e9);">use</button>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-[0.6875rem] font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                                    <input type="text" name="notes" value="{{ $filing->notes }}"
                                           class="rounded-md px-2 py-1 text-xs w-28"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="corex-btn-primary">Save</button>
                                    <button type="button" @click="editing = false" class="corex-btn-outline">Cancel</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            No filing entries found. Click &ldquo;New Filing&rdquo; to add one.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

{{--
    AT-238 — the filing register's property/seller picker.

    One component, used by the New Filing form and by every inline edit row, so a link made
    while creating and a link made while correcting an old row behave identically.

    The rule it enforces: LINKING IS AN UPGRADE, NEVER A GATE. The typed address is always
    submitted, so a filing whose property CoreX has never heard of still saves — about 42% of
    the historical register is exactly that. And the expiry is SUGGESTED, never silently
    rewritten: the register is a legal record of what was filed, not a live mirror of the
    property.
--}}
@push('scripts')
<script>
function filingPicker(initial) {
    return {
        propertyId:      initial.propertyId ?? null,
        address:         initial.address ?? '',
        sellerContactId: initial.sellerContactId ?? null,
        sellerName:      initial.sellerName ?? '',
        expiry:          initial.expiry ?? '',
        branchId:        initial.branchId ?? '',
        agentId:         initial.agentId ?? '',
        suggestedExpiry: null,
        // Which fields the PROPERTY answered for us — drives the "from property" badges, so the
        // clerk can see at a glance what was derived rather than what they must supply.
        derivedFrom: { branch: false, agent: false, seller: false, expiry: false },
        sellers: [],
        results: [],
        open: false,
        _timer: null,

        onType() {
            // Typing a new address means the old link no longer describes what is typed.
            // Drop it rather than leave a link silently disagreeing with the text.
            if (this.propertyId && this.address !== this._linkedLabel) {
                this.propertyId = null;
                this.suggestedExpiry = null;
                this.sellers = [];
            }
            clearTimeout(this._timer);
            const q = (this.address || '').trim();
            if (q.length < 2) { this.results = []; this.open = false; return; }
            this._timer = setTimeout(() => this.search(q), 220);
        },

        async search(q) {
            try {
                const res = await fetch('{{ route('filing-register.search.properties') }}?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) { this.results = []; this.open = false; return; }
                const data = await res.json();
                this.results = data.results || [];
                this.open = this.results.length > 0;
            } catch (e) {
                // A search that cannot reach the server must never block the filing:
                // the typed address still saves.
                this.results = [];
                this.open = false;
            }
        },

        async pick(r) {
            this.propertyId = r.id;
            this.address = r.address || r.label || this.address;
            this._linkedLabel = this.address;
            this.open = false;
            this.results = [];
            await this.loadSuggestions(r.id);
        },

        async loadSuggestions(id) {
            try {
                const url = '{{ route('filing-register.search.property-suggestions', ['property' => '__ID__']) }}'.replace('__ID__', id);
                const res = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                const sug = data.suggestions || {};
                this.sellers = data.sellers || [];
                this.suggestedExpiry = sug.expiry_date || null;

                // Fill only what is EMPTY. Never overwrite a value a human already committed to
                // this row — on an existing filing that value IS the filed fact.
                if (!this.expiry && this.suggestedExpiry) {
                    this.expiry = this.suggestedExpiry;
                    this.derivedFrom.expiry = true;
                }
                if (!this.sellerContactId && !this.sellerName && this.sellers.length) {
                    this.pickSeller(this.sellers[0].id);
                    this.derivedFrom.seller = true;
                }
                // The listing's own branch and agent — the two fields the clerk used to have to
                // know before they could even start. They are suggestions: still editable.
                if (!this.branchId && sug.branch_id) {
                    this.branchId = String(sug.branch_id);
                    this.derivedFrom.branch = true;
                }
                if (!this.agentId && sug.agent_id) {
                    this.agentId = String(sug.agent_id);
                    this.derivedFrom.agent = true;
                }
            } catch (e) { /* suggestions are a convenience, never a blocker */ }
        },

        pickSeller(id) {
            if (!id) { this.sellerContactId = null; return; }
            const s = this.sellers.find(x => String(x.id) === String(id));
            if (!s) return;
            this.sellerContactId = s.id;
            this.sellerName = s.name; // keep the text in step, so an unlinked row still reads right
        },

        unlink() {
            this.propertyId = null;
            this.suggestedExpiry = null;
            this.sellers = [];
            this.sellerContactId = null;
            // Anything the property answered for us stops being an answer. What the human typed
            // themselves stays — unlinking says "CoreX has no record of this", not "start over".
            if (this.derivedFrom.branch) { this.branchId = ''; }
            if (this.derivedFrom.agent)  { this.agentId  = ''; }
            this.derivedFrom = { branch: false, agent: false, seller: false, expiry: false };
            // The address and expiry STAY: unlinking says "CoreX has no record of this",
            // not "forget what I filed".
        },

        closeSoon() { setTimeout(() => { this.open = false; }, 120); },
    };
}
</script>
@endpush
@endsection
