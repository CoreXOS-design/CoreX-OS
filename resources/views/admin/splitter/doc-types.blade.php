{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')

@php
    $isSettings = ($context ?? 'splitter') === 'settings';

    // AT-105 enh — plain-English labels for the routing controls (STANDARDS F.8).
    // contact_roles is a MANY-set (tick any combination); OTP ticks Seller + Buyer.
    $contactRoleOptions = [
        'seller_owner' => 'Seller / Owner',
        'buyer'        => 'Buyer',
        'tenant'       => 'Tenant',
        'landlord'     => 'Landlord',
        'lessor'       => 'Lessor',
    ];
    $ficaSlotOptions = [
        'none'      => '— Not a FICA doc —',
        'fica_form' => 'FICA Form',
        'id'        => 'ID Copy',
        'por'       => 'Proof of Residence',
    ];
@endphp

<div class="w-full space-y-6">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Document Types</h1>
                <p class="text-sm text-white/60">{{ $isSettings ? 'Manage document categories used across CoreX. Assign listing types to control which document folders appear on a property\'s drive.' : 'Manage the label types used in the PDF Pack Splitter review page.' }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if($isSettings)
                    @if(auth()->user()?->hasPermission('deals_v2.manage_distribution_rules') && \Illuminate\Support\Facades\Route::has('admin.settings.deal-distribution-rules.index'))
                        <a href="{{ route('admin.settings.deal-distribution-rules.index') }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);" title="Configure how deal documents are distributed to each party at each stage">Deal distribution rules &rarr;</a>
                    @endif
                    <a href="{{ route('corex.settings', ['tab' => 'system']) }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">&larr; Back to Settings</a>
                @else
                    <a href="{{ route('tools.pdf_splitter.index') }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">&larr; Back to Splitter</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    @php
        $storeRoute = $isSettings ? route('admin.settings.document-types.store') : route('admin.splitter.doc-types.store');
        $bulkSaveRoute = $isSettings ? route('admin.settings.document-types.bulk-save') : route('admin.splitter.doc-types.bulk-save');
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- LEFT: Add New Type --}}
        <div class="lg:col-span-1 space-y-4">
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Add New Type</h3>
                <form method="POST" action="{{ $storeRoute }}" class="space-y-3"
                      x-data="{ contact: {{ old('save_to_contact') ? 'true' : 'false' }} }">
                    @csrf
                    <div>
                        <label for="dt-label" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Label <span style="color: var(--ds-crimson);">*</span></label>
                        <input id="dt-label" type="text" name="label" value="{{ old('label') }}" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                               placeholder="e.g. Title Deed">
                        @error('label')
                            <p class="mt-1 text-xs" style="color: var(--ds-crimson);">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">Slug is auto-generated from the label.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Save to</label>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                <input type="checkbox" name="save_to_property" value="1" {{ old('save_to_property') ? 'checked' : '' }}
                                       class="rounded w-4 h-4" style="accent-color: var(--brand-icon);">
                                Property
                            </label>
                            <label class="flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                <input type="checkbox" name="save_to_contact" value="1" x-model="contact" {{ old('save_to_contact') ? 'checked' : '' }}
                                       class="rounded w-4 h-4" style="accent-color: var(--ds-green);">
                                Contact
                            </label>
                        </div>
                    </div>
                    <div :style="contact ? '' : 'opacity:0.4; pointer-events:none;'" :title="contact ? '' : 'Tick Contact to route this type to a party'">
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Routes to (tick any)</label>
                        <div class="space-y-1">
                            @foreach($contactRoleOptions as $val => $human)
                                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="contact_roles[]" value="{{ $val }}"
                                           {{ in_array($val, (array) old('contact_roles', [])) ? 'checked' : '' }}
                                           class="rounded w-4 h-4" style="accent-color: var(--ds-green);">
                                    {{ $human }}
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">A page of this type can be assigned to any/all of these parties.</p>
                    </div>
                    <div :style="contact ? '' : 'opacity:0.4; pointer-events:none;'">
                        <label for="dt-fica-slot" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">FICA slot</label>
                        <select id="dt-fica-slot" name="fica_slot"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            @foreach($ficaSlotOptions as $val => $human)
                                <option value="{{ $val }}" @selected(old('fica_slot','none')===$val)>{{ $human }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Viewing Pack</label>
                        <label class="flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);"
                               title="When eligible, this document type can be selected into a buyer's Viewing Pack. Leave off for identity / compliance documents.">
                            <input type="checkbox" name="buyer_pack_eligible" value="1" {{ old('buyer_pack_eligible') ? 'checked' : '' }}
                                   class="rounded w-4 h-4" style="accent-color: var(--brand-icon);">
                            Buyer-pack eligible (default)
                        </label>
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">Sets the catalogue default. Each agency can override it per type below.</p>
                    </div>
                    <button type="submit" class="corex-btn-primary w-full">Add Type</button>
                </form>
            </div>

            {{-- Legend --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">How It Works</h3>
                <p class="text-sm leading-relaxed mb-3" style="color: var(--text-secondary);">
                    Assign <strong style="color: var(--text-primary);">listing types</strong> to each document type to control which file upload folders appear on a property's <strong style="color: var(--text-primary);">Drive</strong> tab.
                </p>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-success">For Sale</span>
                        <span>appears on sale listings only</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-info">For Rent</span>
                        <span>appears on rental listings only</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-default">Both</span>
                        <span>appears on all listings</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-default">None</span>
                        <span>appears on no listings</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: Existing Types --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ $bulkSaveRoute }}" id="bulkForm">
                @csrf

                <div class="mb-3 flex items-center justify-between">
                    <p class="text-sm" style="color: var(--text-muted);">Showing {{ number_format($types->count()) }} document {{ \Illuminate\Support\Str::plural('type', $types->count()) }}</p>
                    <button type="submit" class="corex-btn-primary">Save All Changes</button>
                </div>

                @if($types->isEmpty())
                    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No document types yet</h3>
                        <p class="text-sm" style="color: var(--text-muted);">Add your first document type using the form on the left.</p>
                    </div>
                @else
                    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm ds-table">
                                <thead>
                                    <tr style="background: var(--surface-2);">
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted);">Order</th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Label</th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Slug</th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Listing Type</th>
                                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-40" style="color: var(--text-muted);">
                                            <span class="cursor-help" title="When the PDF Splitter files a document of this type, save it to the property record, the linked seller/owner contact, or both. Tick either or both. Applies to this agency only.">Save To &#9432;</span>
                                        </th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-36" style="color: var(--text-muted);">
                                            <span class="cursor-help" title="Which party a split page of this type is assigned to on the review screen. 'Seller / Owner' covers both seller and owner roles. Applies to this agency only.">Routes To &#9432;</span>
                                        </th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-36" style="color: var(--text-muted);">
                                            <span class="cursor-help" title="If this is a FICA document, which wet-ink FICA upload slot it fills (FICA Form, ID Copy, or Proof of Residence). Pages of FICA types are grouped per assigned contact into one verification each. Applies to this agency only.">FICA Slot &#9432;</span>
                                        </th>
                                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-24" style="color: var(--text-muted);">Active</th>
                                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-32" style="color: var(--text-muted);">
                                            <span class="cursor-help" title="When ticked, a property cannot be marketed until at least one document of this type is on its Drive. Applies to this agency only. Photos and listing details are always required.">Compliance required &#9432;</span>
                                        </th>
                                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-28" style="color: var(--text-muted);">
                                            <span class="cursor-help" title="Catalogue default: when ticked, this document type can be selected into a buyer's Viewing Pack across all agencies, unless an agency overrides it.">Buyer Pack (default) &#9432;</span>
                                        </th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-36" style="color: var(--text-muted);">
                                            <span class="cursor-help" title="This agency's override of the buyer-pack default. 'Inherit' uses the catalogue default; 'Eligible' / 'Not eligible' force this agency's own choice.">Buyer Pack (this agency) &#9432;</span>
                                        </th>
                                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted);">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($types as $i => $t)
                                    @php
                                        $assigned = $t->listing_types ?? [];
                                        $dest  = $destinationMap[$t->id] ?? ['property' => true, 'contact' => false];
                                        $route = $routingMap[$t->id] ?? ['contact_roles' => [], 'fica_slot' => 'none'];
                                        // Viewing Pack — per-agency override: present in the map only
                                        // when set (true/false); absent => inherit the catalogue default.
                                        $eligOverride = array_key_exists($t->id, $eligibilityMap ?? [])
                                            ? ($eligibilityMap[$t->id] ? 'yes' : 'no')
                                            : 'inherit';
                                    @endphp
                                    {{-- AT-105 — Routes-To/FICA are gated on the Contact destination (meaningless
                                         without it). Greyed via pointer-events (NOT `disabled`) so saved roles still
                                         POST and are never wiped when Contact is un-ticked. --}}
                                    <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                                        x-data="{ contact: {{ $dest['contact'] ? 'true' : 'false' }} }">
                                        <td class="px-4 py-3">
                                            <input type="hidden" name="types[{{ $i }}][id]" value="{{ $t->id }}">
                                            <input type="number" name="types[{{ $i }}][sort_order]" value="{{ $t->sort_order }}"
                                                   class="w-16 rounded-md px-2 py-1 text-sm text-center"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                   min="0">
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="text" name="types[{{ $i }}][label]" value="{{ $t->label }}"
                                                   class="w-full rounded-md px-2 py-1 text-sm"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">{{ $t->slug }}</td>
                                        <td class="px-4 py-3">
                                            <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                                                <button type="button" @click="open = !open"
                                                        class="w-full flex items-center justify-between gap-1 rounded-md px-2 py-1.5 text-sm text-left transition-colors"
                                                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary); min-width: 140px;">
                                                    <span class="truncate">
                                                        @if(empty($assigned) || count($assigned) === 2)
                                                            <span class="ds-badge ds-badge-default">All listings</span>
                                                        @elseif(in_array('sale', $assigned))
                                                            <span class="ds-badge ds-badge-success">For Sale</span>
                                                        @elseif(in_array('rental', $assigned))
                                                            <span class="ds-badge ds-badge-info">For Rent</span>
                                                        @endif
                                                    </span>
                                                    <svg class="w-3 h-3 flex-shrink-0 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color: var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </button>
                                                <div x-show="open" x-cloak x-transition
                                                     class="absolute z-50 mt-1 w-44 rounded-md py-1"
                                                     style="background: var(--surface); border: 1px solid var(--border); right: 0; box-shadow: 0 8px 24px rgba(0,0,0,0.4);">
                                                    <label class="flex items-center gap-2 px-3 py-2 cursor-pointer text-sm transition-colors hover:bg-[var(--surface-2)]"
                                                           style="color: var(--text-secondary);">
                                                        <input type="checkbox"
                                                               name="types[{{ $i }}][listing_types][]"
                                                               value="sale"
                                                               {{ in_array('sale', $assigned) ? 'checked' : '' }}
                                                               class="rounded" style="accent-color: var(--ds-green);">
                                                        <span>For Sale</span>
                                                    </label>
                                                    <label class="flex items-center gap-2 px-3 py-2 cursor-pointer text-sm transition-colors hover:bg-[var(--surface-2)]"
                                                           style="color: var(--text-secondary);">
                                                        <input type="checkbox"
                                                               name="types[{{ $i }}][listing_types][]"
                                                               value="rental"
                                                               {{ in_array('rental', $assigned) ? 'checked' : '' }}
                                                               class="rounded" style="accent-color: var(--brand-icon);">
                                                        <span>For Rent</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-center gap-3">
                                                <label class="flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-secondary);"
                                                       title="Save a {{ $t->label }} to the property's Drive.">
                                                    <input type="checkbox" name="types[{{ $i }}][save_to_property]" value="1"
                                                           {{ $dest['property'] ? 'checked' : '' }}
                                                           class="rounded w-4 h-4 cursor-pointer" style="accent-color: var(--brand-icon);">
                                                    Property
                                                </label>
                                                <label class="flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-secondary);"
                                                       title="Save a {{ $t->label }} to the linked contact(s). Enables Routes-To + FICA Slot.">
                                                    <input type="checkbox" name="types[{{ $i }}][save_to_contact]" value="1" x-model="contact"
                                                           {{ $dest['contact'] ? 'checked' : '' }}
                                                           class="rounded w-4 h-4 cursor-pointer" style="accent-color: var(--ds-green);">
                                                    Contact
                                                </label>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="space-y-1" :style="contact ? '' : 'opacity:0.4; pointer-events:none;'"
                                                 :title="contact ? '' : 'Tick Contact to route this type to a party'">
                                                @foreach($contactRoleOptions as $val => $human)
                                                    <label class="flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-secondary);">
                                                        <input type="checkbox" name="types[{{ $i }}][contact_roles][]" value="{{ $val }}"
                                                               {{ in_array($val, $route['contact_roles'] ?? []) ? 'checked' : '' }}
                                                               class="rounded w-3.5 h-3.5" style="accent-color: var(--ds-green);">
                                                        {{ $human }}
                                                    </label>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <select name="types[{{ $i }}][fica_slot]"
                                                    class="w-full rounded-md px-2 py-1 text-sm"
                                                    :style="contact ? 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); opacity:0.4; pointer-events:none;'">
                                                @foreach($ficaSlotOptions as $val => $human)
                                                    <option value="{{ $val }}" @selected($route['fica_slot']===$val)>{{ $human }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <select name="types[{{ $i }}][is_active]"
                                                    class="rounded-md px-2 py-1 text-sm"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="1" {{ $t->is_active ? 'selected' : '' }}>Yes</option>
                                                <option value="0" {{ !$t->is_active ? 'selected' : '' }}>No</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox" name="types[{{ $i }}][compliance_required]" value="1"
                                                   {{ ($complianceMap[$t->id] ?? false) ? 'checked' : '' }}
                                                   class="rounded w-4 h-4 cursor-pointer" style="accent-color: var(--ds-green);"
                                                   title="Require a {{ $t->label }} on the property Drive before it can be marketed (this agency only).">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            {{-- Viewing Pack catalogue default (global) — checkbox. --}}
                                            <input type="checkbox" name="types[{{ $i }}][buyer_pack_eligible]" value="1"
                                                   {{ $t->buyer_pack_eligible ? 'checked' : '' }}
                                                   class="rounded w-4 h-4 cursor-pointer" style="accent-color: var(--brand-icon);"
                                                   title="Catalogue default: allow a {{ $t->label }} to be selected into a buyer's Viewing Pack (all agencies, unless overridden below).">
                                        </td>
                                        <td class="px-4 py-3">
                                            {{-- Viewing Pack per-agency override — tri-state. --}}
                                            <select name="types[{{ $i }}][buyer_pack_eligible_override]"
                                                    class="w-full rounded-md px-2 py-1 text-sm"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                    title="Override the catalogue default for this agency only.">
                                                <option value="inherit" @selected($eligOverride === 'inherit')>Inherit (default)</option>
                                                <option value="yes" @selected($eligOverride === 'yes')>Eligible</option>
                                                <option value="no" @selected($eligOverride === 'no')>Not eligible</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <button type="button" onclick="deleteDocType('{{ route('admin.splitter.doc-types.destroy', $t) }}', '{{ addslashes($t->label) }}')"
                                                    class="text-xs font-semibold transition-colors"
                                                    style="color: var(--ds-crimson);"
                                                    title="Delete">Delete</button>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </form>
        </div>
    </div>

</div>

{{-- Hidden delete form --}}
<form id="deleteDocTypeForm" method="POST" action="" style="display:none;">
    @csrf
    @method('DELETE')
</form>

<script>
function deleteDocType(url, label) {
    if (!confirm('Delete \'' + label + '\'?')) return;
    var form = document.getElementById('deleteDocTypeForm');
    form.action = url;
    form.submit();
}
</script>

@endsection
