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
    $storeRoute    = $isSettings ? route('admin.settings.document-types.store') : route('admin.splitter.doc-types.store');
    $bulkSaveRoute = $isSettings ? route('admin.settings.document-types.bulk-save') : route('admin.splitter.doc-types.bulk-save');
@endphp

{{-- AT-166 — component-scoped styles. Own classes (NOT Tailwind arbitrary utilities),
     so they survive the git-pull + view:clear deploy with no `npm run build` (calendar §3). --}}
<style>
    [x-cloak] { display: none !important; }
    .dt-pill {
        display: inline-flex; align-items: center; gap: .375rem;
        padding: .3rem .7rem; border-radius: 9999px; cursor: pointer;
        font-size: .78rem; line-height: 1; user-select: none; white-space: nowrap;
        border: 1px solid var(--border); color: var(--text-secondary);
        background: var(--surface); transition: background .12s, border-color .12s, color .12s;
    }
    .dt-pill:hover { border-color: var(--border-hover, var(--brand-icon)); }
    .dt-pill[data-on="true"] {
        background: color-mix(in srgb, var(--brand-icon) 16%, transparent);
        border-color: var(--brand-icon); color: var(--brand-icon); font-weight: 600;
    }
    .dt-pill[data-on="true"].dt-pill-green {
        background: color-mix(in srgb, var(--ds-green) 16%, transparent);
        border-color: var(--ds-green); color: var(--ds-green);
    }
    .dt-pill input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
    .dt-group[data-gated="true"] { opacity: .4; pointer-events: none; }
    .dt-row-head { display: flex; align-items: center; gap: .75rem; padding: .625rem .875rem; cursor: pointer; }
    .dt-row-head:hover { background: var(--surface-2); }
    .dt-chevron { transition: transform .15s; }
    .dt-chevron[data-open="true"] { transform: rotate(180deg); }
    .dt-field-label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-bottom: .4rem; }
</style>

<div class="w-full space-y-6" x-data="docTypesPage()">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Document Types</h1>
                <p class="text-sm text-white/60">{{ $isSettings ? 'Manage document categories used across CoreX. Assign listing types to control which document folders appear on a property\'s drive.' : 'Manage the label types used in the PDF Pack Splitter review page.' }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if($isSettings)
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- LEFT: Add New Type (unchanged behaviour) + Legend --}}
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
                    Each document type is a full-width row — click it to open its settings. Assign <strong style="color: var(--text-primary);">listing types</strong> to control which upload folders appear on a property's <strong style="color: var(--text-primary);">Drive</strong> tab.
                </p>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);"><span class="ds-badge ds-badge-success">For Sale</span><span>sale listings only</span></div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);"><span class="ds-badge ds-badge-info">For Rent</span><span>rental listings only</span></div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);"><span class="ds-badge ds-badge-default">All listings</span><span>every listing</span></div>
                </div>
            </div>
        </div>

        {{-- RIGHT: search + Save All + full-width expandable rows (NO horizontal scroll) --}}
        <div class="lg:col-span-2">

            {{-- Search / filter (client-side; not a form field) --}}
            <div class="mb-3 relative">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--text-muted);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <input type="text" x-model="q" placeholder="Search document types by name or slug…"
                       class="w-full rounded-md pl-9 pr-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            <form method="POST" action="{{ $bulkSaveRoute }}" id="bulkForm">
                @csrf

                <div class="mb-3 flex items-center justify-between gap-3">
                    <p class="text-sm" style="color: var(--text-muted);">
                        <span x-show="q === ''">Showing {{ number_format($types->count()) }} document {{ \Illuminate\Support\Str::plural('type', $types->count()) }}</span>
                        <span x-show="q !== ''" x-cloak><span x-text="shown"></span> of {{ number_format($types->count()) }} shown</span>
                    </p>
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
                    <div class="space-y-2">
                        @foreach($types as $i => $t)
                        @php
                            $assigned = $t->listing_types ?? [];
                            $dest  = $destinationMap[$t->id] ?? ['property' => true, 'contact' => false];
                            $route = $routingMap[$t->id] ?? ['contact_roles' => [], 'fica_slot' => 'none'];
                            $eligOverride = array_key_exists($t->id, $eligibilityMap ?? [])
                                ? ($eligibilityMap[$t->id] ? 'yes' : 'no')
                                : 'inherit';
                            $hay = \Illuminate\Support\Str::lower($t->label . ' ' . $t->slug);
                            // Collapsed-summary listing-type badge
                            if (empty($assigned) || count($assigned) === 2) { $ltBadge = ['All listings', 'ds-badge-default']; }
                            elseif (in_array('sale', $assigned)) { $ltBadge = ['For Sale', 'ds-badge-success']; }
                            else { $ltBadge = ['For Rent', 'ds-badge-info']; }
                        @endphp
                        {{-- FULL-WIDTH ROW. Every types[..] field stays in the DOM (x-show = display:none,
                             which still POSTs) so Save All captures collapsed AND filtered rows. Contact
                             gating uses opacity/pointer-events (NOT `disabled`) so roles/FICA still POST
                             when Contact is un-ticked. --}}
                        <div class="dt-row rounded-md" style="background: var(--surface); border: 1px solid var(--border);"
                             x-show="matches(@js($hay))"
                             x-data="{ contact: {{ $dest['contact'] ? 'true' : 'false' }}, open: false }">
                            <input type="hidden" name="types[{{ $i }}][id]" value="{{ $t->id }}">

                            {{-- COLLAPSED HEAD — label + slug always visible --}}
                            <div class="dt-row-head" @click="open = !open">
                                {{-- order --}}
                                <div @click.stop title="Display order">
                                    <input type="number" name="types[{{ $i }}][sort_order]" value="{{ $t->sort_order }}" min="0"
                                           class="w-14 rounded-md px-2 py-1 text-sm text-center"
                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                {{-- label (full, editable) + slug --}}
                                <div class="flex-1 min-w-0" @click.stop>
                                    <input type="text" name="types[{{ $i }}][label]" value="{{ $t->label }}"
                                           class="w-full rounded-md px-2 py-1 text-sm font-medium"
                                           style="background: transparent; border: 1px solid transparent; color: var(--text-primary);"
                                           onfocus="this.style.background='var(--surface-2)';this.style.borderColor='var(--border)';"
                                           onblur="this.style.background='transparent';this.style.borderColor='transparent';">
                                    <span class="font-mono text-xs px-2" style="color: var(--text-muted);">{{ $t->slug }}</span>
                                </div>
                                {{-- collapsed summary badges --}}
                                <div class="hidden sm:flex items-center gap-1.5 flex-shrink-0">
                                    <span class="ds-badge {{ $ltBadge[1] }}">{{ $ltBadge[0] }}</span>
                                    @if($dest['property'])<span class="ds-badge ds-badge-default">Property</span>@endif
                                    @if($dest['contact'])<span class="ds-badge ds-badge-default">Contact</span>@endif
                                    @if(!$t->is_active)<span class="ds-badge ds-badge-default" style="opacity:.7;">Inactive</span>@endif
                                </div>
                                {{-- active toggle (Yes/No) --}}
                                <div @click.stop title="Active">
                                    <select name="types[{{ $i }}][is_active]" class="rounded-md px-2 py-1 text-sm"
                                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                        <option value="1" {{ $t->is_active ? 'selected' : '' }}>Active</option>
                                        <option value="0" {{ !$t->is_active ? 'selected' : '' }}>Inactive</option>
                                    </select>
                                </div>
                                {{-- delete --}}
                                <button type="button" @click.stop="" onclick="deleteDocType('{{ route('admin.splitter.doc-types.destroy', $t) }}', '{{ addslashes($t->label) }}')"
                                        class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-crimson);" title="Delete">Delete</button>
                                {{-- chevron --}}
                                <svg class="dt-chevron w-4 h-4 flex-shrink-0" :data-open="open" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color: var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>

                            {{-- EXPANDED BODY — grouped toggle pills --}}
                            <div x-show="open" x-cloak x-transition.opacity
                                 class="px-4 pb-4 pt-1" style="border-top: 1px solid var(--border);">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 pt-3">

                                    {{-- Listing Type --}}
                                    <div>
                                        <div class="dt-field-label">Listing type <span class="normal-case font-normal" style="color:var(--text-muted);">— which Drive folders show</span></div>
                                        <div class="flex flex-wrap gap-2">
                                            <label class="dt-pill" x-data="{ on: {{ in_array('sale', $assigned) ? 'true':'false' }} }" :data-on="on">
                                                <input type="checkbox" name="types[{{ $i }}][listing_types][]" value="sale" x-model="on">For Sale
                                            </label>
                                            <label class="dt-pill" x-data="{ on: {{ in_array('rental', $assigned) ? 'true':'false' }} }" :data-on="on">
                                                <input type="checkbox" name="types[{{ $i }}][listing_types][]" value="rental" x-model="on">For Rent
                                            </label>
                                        </div>
                                    </div>

                                    {{-- Save to --}}
                                    <div>
                                        <div class="dt-field-label">Save to <span class="normal-case font-normal" style="color:var(--text-muted);">— where the splitter files it</span></div>
                                        <div class="flex flex-wrap gap-2">
                                            <label class="dt-pill" x-data="{ on: {{ $dest['property'] ? 'true':'false' }} }" :data-on="on">
                                                <input type="checkbox" name="types[{{ $i }}][save_to_property]" value="1" x-model="on">Property
                                            </label>
                                            <label class="dt-pill dt-pill-green" :data-on="contact">
                                                <input type="checkbox" name="types[{{ $i }}][save_to_contact]" value="1" x-model="contact">Contact
                                            </label>
                                        </div>
                                    </div>

                                    {{-- Routes to (gated on Contact) --}}
                                    <div class="dt-group" :data-gated="!contact" :title="contact ? '' : 'Tick Contact (Save to) to route this type to a party'">
                                        <div class="dt-field-label">Routes to <span class="normal-case font-normal" style="color:var(--text-muted);">— which party a page is assigned to</span></div>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($contactRoleOptions as $val => $human)
                                                <label class="dt-pill dt-pill-green" x-data="{ on: {{ in_array($val, $route['contact_roles'] ?? []) ? 'true':'false' }} }" :data-on="on">
                                                    <input type="checkbox" name="types[{{ $i }}][contact_roles][]" value="{{ $val }}" x-model="on">{{ $human }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    {{-- FICA slot (gated on Contact) --}}
                                    <div class="dt-group" :data-gated="!contact">
                                        <div class="dt-field-label">FICA slot <span class="normal-case font-normal" style="color:var(--text-muted);">— wet-ink slot this fills</span></div>
                                        <select name="types[{{ $i }}][fica_slot]" class="w-full max-w-xs rounded-md px-2 py-1.5 text-sm"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            @foreach($ficaSlotOptions as $val => $human)
                                                <option value="{{ $val }}" @selected($route['fica_slot']===$val)>{{ $human }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    {{-- Compliance required (per-agency) --}}
                                    <div>
                                        <div class="dt-field-label">Compliance</div>
                                        <label class="dt-pill dt-pill-green" x-data="{ on: {{ ($complianceMap[$t->id] ?? false) ? 'true':'false' }} }" :data-on="on"
                                               title="Require a {{ $t->label }} on the property Drive before it can be marketed (this agency only).">
                                            <input type="checkbox" name="types[{{ $i }}][compliance_required]" value="1" x-model="on">Required to market
                                        </label>
                                    </div>

                                    {{-- Buyer pack (catalogue default + per-agency override) --}}
                                    <div>
                                        <div class="dt-field-label">Buyer pack (Viewing Pack)</div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <label class="dt-pill" x-data="{ on: {{ $t->buyer_pack_eligible ? 'true':'false' }} }" :data-on="on"
                                                   title="Catalogue default: allow a {{ $t->label }} into a buyer's Viewing Pack (all agencies, unless overridden).">
                                                <input type="checkbox" name="types[{{ $i }}][buyer_pack_eligible]" value="1" x-model="on">Eligible (default)
                                            </label>
                                            <select name="types[{{ $i }}][buyer_pack_eligible_override]" class="rounded-md px-2 py-1.5 text-sm"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                    title="This agency's override of the catalogue default.">
                                                <option value="inherit" @selected($eligOverride === 'inherit')>This agency: inherit</option>
                                                <option value="yes" @selected($eligOverride === 'yes')>This agency: eligible</option>
                                                <option value="no" @selected($eligOverride === 'no')>This agency: not eligible</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                        @endforeach
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
function docTypesPage() {
    return {
        q: '',
        get shown() {
            if (this.q === '') return {{ $types->count() }};
            return this.$root.querySelectorAll('.dt-row:not([style*="display: none"])').length;
        },
        matches(hay) { return this.q === '' || hay.indexOf(this.q.toLowerCase().trim()) !== -1; },
    };
}
</script>

@endsection
