@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §4/§5/§7 — the inventory detail screen:
    header (property/lease/parties), the line list grouped by room, and
    three-party signing (tenant(s)/landlord/agent — same shape as
    rental-inspections.md §15, refusal a first-class disposition, the
    agent signing last). Its own page, not a tab on the inspection.
--}}

@php
    $statusBadgeClass = match($inventory->status) {
        'completed' => 'ds-badge-success',
        'cancelled' => 'ds-badge-danger',
        'awaiting_signature' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
    $linesByRoom = $inventory->lines->groupBy('room_label');
@endphp

@section('content')
<div class="p-6 max-w-4xl mx-auto space-y-4" x-data="rentalInventoryShow({{ $inventory->id }})">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">Inventory — {{ $inventory->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $inventory->status)) }}</span>
                Started {{ $inventory->created_at?->format('Y-m-d') }} by {{ $inventory->createdBy?->name ?? '—' }}
            </p>
        </div>
        <a href="{{ route('corex.rental-inventories.index') }}" class="corex-btn-outline text-xs">Back to list</a>
    </div>

    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); color: var(--ds-green,#059669);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Header: landlord/tenants — display only, pulled from the same relations §17's header block already uses. --}}
    <div class="rounded-md p-4 grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
        <div><span style="color: var(--text-muted);">Landlord:</span> {{ optional($inventory->property?->sellerOwnerContact())->full_name ?? '—' }}</div>
        @foreach($inventory->lease?->tenants ?? [] as $idx => $tenant)
            <div><span style="color: var(--text-muted);">Tenant {{ $idx + 1 }}:</span> {{ $tenant->contact?->full_name ?? '—' }}</div>
        @endforeach
        <div><span style="color: var(--text-muted);">Inspection done by:</span> {{ $inventory->createdBy?->name ?? '—' }}</div>
    </div>

    {{-- Lines, grouped by room — quantity + free-text description, per Johan's real document. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Items</h2>

        @forelse($linesByRoom as $room => $lines)
            <div class="space-y-1">
                <h3 class="text-xs font-bold uppercase tracking-wide" style="color: var(--text-secondary);">{{ $room }}</h3>
                @foreach($lines as $line)
                    <div class="flex items-start justify-between gap-3 py-1 text-sm" style="border-bottom: 1px solid var(--border);">
                        <div><span class="font-semibold">{{ $line->quantity }}x</span> {{ $line->description }}</div>
                        @permission('rental_inventories.create')
                        @if(!in_array($inventory->status, ['completed', 'cancelled']))
                        <form method="POST" action="{{ route('corex.rental-inventories.lines.retire', [$inventory, $line]) }}" onsubmit="return confirm('Remove this line? It stays in the record, marked removed.');" class="shrink-0">
                            @csrf
                            <button type="submit" class="text-xs" style="color: var(--ds-crimson,#c41e3a); background:none; border:none; cursor:pointer;">Remove</button>
                        </form>
                        @endif
                        @endpermission
                    </div>
                @endforeach
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No items recorded yet.</p>
        @endforelse

        @permission('rental_inventories.create')
        @if(!in_array($inventory->status, ['completed', 'cancelled']))
        <form method="POST" action="{{ route('corex.rental-inventories.lines.store', $inventory) }}" class="grid grid-cols-1 sm:grid-cols-6 gap-2 pt-2" style="border-top: 1px solid var(--border);">
            @csrf
            <input type="text" name="room_label" required placeholder="Room (e.g. Lounge)" list="room-labels" class="prop-input sm:col-span-2">
            <datalist id="room-labels">
                @foreach($linesByRoom->keys() as $room)<option value="{{ $room }}"></option>@endforeach
            </datalist>
            <input type="number" name="quantity" min="0" required placeholder="Qty" class="prop-input" style="width:5rem;">
            <input type="text" name="description" required placeholder="e.g. Wooden TV table" class="prop-input sm:col-span-2">
            <button type="submit" class="corex-btn-outline text-xs">Add item</button>
        </form>
        @endif
        @endpermission
    </div>

    {{-- Signatures — same rendering rule as rental-inspections.md §15.5: branches on
         disposition alone, a refused row never presentable as a signature. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Signatures</h2>

        @foreach($inventory->signatures as $signature)
            @php
                $partyLabel = match($signature->party_role) {
                    'agent' => 'Agent',
                    'landlord' => 'Landlord' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                    default => 'Tenant' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                };
                $reasonLabel = collect($refusalReasonPresets)->firstWhere('key', $signature->refusal_reason_preset)['label'] ?? $signature->refusal_reason_preset;
            @endphp
            <div class="text-sm py-2" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span>{{ $partyLabel }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $signature->disposition_recorded_at?->format('Y-m-d H:i') }}</span>
                </div>
                @if($signature->disposition === 'signed')
                    <div class="mt-1.5">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Signed</span>
                        @if($signature->party_signature_path)
                            <div class="mt-1"><img src="{{ $signature->party_signature_path }}" alt="{{ $partyLabel }}'s signature" style="max-height: 60px; background:#fff; border:1px solid var(--border); border-radius:4px; padding:4px;"></div>
                        @endif
                    </div>
                @else
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Refused to sign</span>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">Reason: {{ $reasonLabel }}{{ $signature->refusal_reason_note ? ' — ' . $signature->refusal_reason_note : '' }}</div>
                    </div>
                @endif
            </div>
        @endforeach

        @permission('rental_inventories.create')
        @if(!in_array($inventory->status, ['completed', 'cancelled']))
        <div class="space-y-2 pt-2">
            @foreach($inventory->lease?->tenants ?? [] as $tenant)
                <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm" x-text="tenantName({{ $tenant->contact_id }}, '{{ addslashes($tenant->contact?->full_name ?? 'Tenant') }}')"></span>
                        <template x-if="dispositionFor('tenant', {{ $tenant->contact_id }})">
                            <span class="text-xs font-semibold uppercase" style="color:var(--text-muted);" x-text="dispositionFor('tenant', {{ $tenant->contact_id }}).disposition === 'refused' ? 'Refused' : 'Signed'"></span>
                        </template>
                        <template x-if="!dispositionFor('tenant', {{ $tenant->contact_id }})">
                            <div class="flex items-center gap-2">
                                <button type="button" @click="openSigningFor('tenant_{{ $tenant->contact_id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Sign</button>
                                <button type="button" @click="openRefusalFor('tenant_{{ $tenant->contact_id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Refuses</button>
                            </div>
                        </template>
                    </div>
                    <template x-if="activeSigningKey === 'tenant_{{ $tenant->contact_id }}'">
                        <div class="space-y-2 pt-2">
                            <canvas x-init="$nextTick(() => initSignaturePadFor('tenant_{{ $tenant->contact_id }}', $el))" class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="clearSignatureFor('tenant_{{ $tenant->contact_id }}')" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Clear</button>
                                <button type="button" @click="saveSignatureFor('tenant', {{ $tenant->contact_id }})" class="text-xs px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="activeRefusalKey === 'tenant_{{ $tenant->contact_id }}'">
                        <div class="space-y-2 pt-2">
                            <select x-model="refusalField('tenant_{{ $tenant->contact_id }}').preset" class="prop-input w-full">
                                <option value="">Select a reason…</option>
                                @foreach($refusalReasonPresets as $preset)<option value="{{ $preset['key'] }}">{{ $preset['label'] }}</option>@endforeach
                            </select>
                            <input type="text" x-show="refusalField('tenant_{{ $tenant->contact_id }}').preset === 'other'" x-model="refusalField('tenant_{{ $tenant->contact_id }}').note" placeholder="Note (required for 'Other')" class="prop-input w-full">
                            <button type="button" @click="saveRefusalFor('tenant', {{ $tenant->contact_id }})" :disabled="!refusalField('tenant_{{ $tenant->contact_id }}').preset" class="text-xs px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Record refusal</button>
                        </div>
                    </template>
                </div>
            @endforeach

            @if($landlordContact = $inventory->property?->sellerOwnerContact())
                <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm">{{ $landlordContact->full_name }} (Landlord)</span>
                        <template x-if="dispositionFor('landlord', {{ $landlordContact->id }})">
                            <span class="text-xs font-semibold uppercase" style="color:var(--text-muted);" x-text="dispositionFor('landlord', {{ $landlordContact->id }}).disposition === 'refused' ? 'Refused' : 'Signed'"></span>
                        </template>
                        <template x-if="!dispositionFor('landlord', {{ $landlordContact->id }})">
                            <div class="flex items-center gap-2">
                                <button type="button" @click="openSigningFor('landlord_{{ $landlordContact->id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Sign</button>
                                <button type="button" @click="openRefusalFor('landlord_{{ $landlordContact->id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Refuses</button>
                            </div>
                        </template>
                    </div>
                    <template x-if="activeSigningKey === 'landlord_{{ $landlordContact->id }}'">
                        <div class="space-y-2 pt-2">
                            <canvas x-init="$nextTick(() => initSignaturePadFor('landlord_{{ $landlordContact->id }}', $el))" class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="clearSignatureFor('landlord_{{ $landlordContact->id }}')" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Clear</button>
                                <button type="button" @click="saveSignatureFor('landlord', {{ $landlordContact->id }})" class="text-xs px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="activeRefusalKey === 'landlord_{{ $landlordContact->id }}'">
                        <div class="space-y-2 pt-2">
                            <select x-model="refusalField('landlord_{{ $landlordContact->id }}').preset" class="prop-input w-full">
                                <option value="">Select a reason…</option>
                                @foreach($refusalReasonPresets as $preset)<option value="{{ $preset['key'] }}">{{ $preset['label'] }}</option>@endforeach
                            </select>
                            <input type="text" x-show="refusalField('landlord_{{ $landlordContact->id }}').preset === 'other'" x-model="refusalField('landlord_{{ $landlordContact->id }}').note" placeholder="Note (required for 'Other')" class="prop-input w-full">
                            <button type="button" @click="saveRefusalFor('landlord', {{ $landlordContact->id }})" :disabled="!refusalField('landlord_{{ $landlordContact->id }}').preset" class="text-xs px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Record refusal</button>
                        </div>
                    </template>
                </div>
            @else
                <p class="text-xs" style="color: var(--text-muted);">Landlord: not linked to this property — nothing to sign.</p>
            @endif

            <div class="py-1.5">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm font-semibold">Agent</span>
                    <template x-if="dispositionFor('agent', null)"><span class="text-xs font-semibold uppercase" style="color:var(--text-muted);">Signed</span></template>
                    <template x-if="!dispositionFor('agent', null) && allRequiredPartiesDispositioned">
                        <button type="button" @click="openSigningFor('agent')" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Sign</button>
                    </template>
                </div>
                <template x-if="activeSigningKey === 'agent'">
                    <div class="space-y-2 pt-2">
                        <canvas x-init="$nextTick(() => initSignaturePadFor('agent', $el))" class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="clearSignatureFor('agent')" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Clear</button>
                            <button type="button" @click="saveSignatureFor('agent', null)" class="text-xs px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="lifecycleError" x-cloak class="text-xs" style="color:#ef4444;" x-text="lifecycleError"></div>
            <div class="flex justify-end">
                <button type="button" @click="completeInventory()" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button,#0ea5e9);">Complete</button>
            </div>
        </div>
        @endif
        @endpermission
    </div>

    @permission('rental_inventories.create')
    @if(!in_array($inventory->status, ['completed', 'cancelled']))
    <form method="POST" action="{{ route('corex.rental-inventories.cancel', $inventory) }}" onsubmit="return confirm('Cancel this inventory?');" class="pt-2">
        @csrf
        <input type="hidden" name="cancel_reason" value="Cancelled by agent">
        <button type="submit" class="text-xs" style="color: var(--ds-crimson,#c41e3a); background:none; border:none; cursor:pointer;">Cancel this inventory</button>
    </form>
    @endif
    @endpermission
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
function rentalInventoryShow(inventoryId) {
    return {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        baseUrl: `/corex/rental-inventories/${inventoryId}`,
        signatures: @json($inventory->signatures->map(fn($s) => ['party_role' => $s->party_role, 'party_contact_id' => $s->party_contact_id, 'disposition' => $s->disposition])),
        landlordContactId: {{ $inventory->property?->sellerOwnerContact()?->id ?? 'null' }},
        tenantContactIds: @json(($inventory->lease?->tenants ?? collect())->pluck('contact_id')),

        activeSigningKey: null,
        activeRefusalKey: null,
        signaturePads: {},
        refusalForm: {},
        lifecycleError: '',

        dispositionFor(role, contactId) {
            return this.signatures.find(s => s.party_role === role && (role === 'agent' || Number(s.party_contact_id) === Number(contactId))) || null;
        },
        get allRequiredPartiesDispositioned() {
            const tenantsOk = this.tenantContactIds.every(id => this.dispositionFor('tenant', id));
            const landlordOk = !this.landlordContactId || this.dispositionFor('landlord', this.landlordContactId);
            return tenantsOk && landlordOk;
        },
        tenantName(contactId, fallback) { return fallback; },

        openSigningFor(key) { this.activeRefusalKey = null; this.activeSigningKey = this.activeSigningKey === key ? null : key; },
        openRefusalFor(key) { this.activeSigningKey = null; this.activeRefusalKey = this.activeRefusalKey === key ? null : key; },
        refusalField(key) { return this.refusalForm[key] || (this.refusalForm[key] = { preset: '', note: '' }); },
        initSignaturePadFor(key, canvasEl) {
            if (!canvasEl || typeof SignaturePad === 'undefined') return;
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvasEl.width = canvasEl.offsetWidth * ratio;
            canvasEl.height = 140 * ratio;
            canvasEl.getContext('2d').scale(ratio, ratio);
            this.signaturePads[key] = new SignaturePad(canvasEl, { backgroundColor: '#fff' });
        },
        clearSignatureFor(key) { this.signaturePads[key]?.clear(); },

        async saveSignatureFor(role, contactId) {
            const key = contactId ? `${role}_${contactId}` : role;
            const pad = this.signaturePads[key];
            if (!pad || pad.isEmpty()) { this.lifecycleError = 'Draw a signature first.'; return; }
            await this._save({ party_role: role, disposition: 'signed', party_contact_id: contactId, signature_image: pad.toDataURL('image/png') }, key, true);
        },
        async saveRefusalFor(role, contactId) {
            const key = `${role}_${contactId}`;
            const form = this.refusalField(key);
            if (!form.preset) return;
            await this._save({ party_role: role, disposition: 'refused', party_contact_id: contactId, refusal_reason_preset: form.preset, refusal_reason_note: form.note || null }, key, false);
        },
        async _save(payload, key, isSigning) {
            this.lifecycleError = '';
            try {
                const res = await fetch(`${this.baseUrl}/signatures`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (!res.ok) { const j = await res.json().catch(() => ({})); throw new Error(j.message || `Request failed (${res.status}).`); }
                const signature = await res.json();
                this.signatures.push({ party_role: signature.party_role, party_contact_id: signature.party_contact_id, disposition: signature.disposition });
                if (isSigning) this.activeSigningKey = null; else this.activeRefusalKey = null;
            } catch (e) { this.lifecycleError = e.message; }
        },
        async completeInventory() {
            this.lifecycleError = '';
            try {
                const res = await fetch(`${this.baseUrl}/complete`, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } });
                if (!res.ok) { const j = await res.json().catch(() => ({})); throw new Error(j.message || `Request failed (${res.status}).`); }
                window.location.reload();
            } catch (e) { this.lifecycleError = e.message; }
        },
    };
}
</script>
@endpush
