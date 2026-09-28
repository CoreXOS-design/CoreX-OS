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
    // Johan, 2026-09-28, property 5294/inventory 8 — "lists only Bedroom 1."
    // Grouping by room_label only ever showed rooms that already had a
    // line; every OTHER real space (a room with zero items, or one only
    // marked empty) simply never rendered. Grouped by property_room_id now
    // — $rooms (every real PropertyRoom, controller-supplied) drives the
    // section below, this is just the per-room line lookup. A line with no
    // property_room_id at all (pre-§0b legacy data, or the free-text
    // "Add item" form's own room_label) still needs somewhere to live —
    // grouped separately by its own room_label, appended after the real
    // rooms, same fallback RentalInventoryReportPdfService already uses.
    $linesByRoomId = $inventory->lines->groupBy('property_room_id');
    $legacyLineGroups = $linesByRoomId->get(null, collect())->groupBy('room_label');
    $markedEmptyRoomIds = $inventory->roomMarks->pluck('property_room_id')->flip();
    // §0a/§15 — a property-level inventory (no lease) is most commonly a
    // sale, so the owner-side party reads as "Seller" rather than
    // "Landlord" there; a lease-attached inventory is unchanged. The
    // underlying party_role stored on RentalInventorySignature stays
    // 'landlord' either way (§5) — this is a display label only.
    $ownerPartyLabel = $inventory->lease_id ? 'Landlord' : 'Seller';
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
        <div class="flex items-center gap-2">
            @if($inventory->status === \App\Models\RentalInventory::STATUS_COMPLETED && $inventory->lease_id)
                <a href="{{ route('corex.rental-inventories.comparison', $inventory) }}" class="corex-btn-outline text-xs">Move-out comparison</a>
            @endif
            <a href="{{ route('corex.rental-inventories.index') }}" class="corex-btn-outline text-xs">Back to list</a>
        </div>
    </div>

    {{-- §41-follow-up (Job 3, 2026-09-28) — "how do we print/share it now,"
         same ask, same shape as the inspection report's own controls: a
         completed inventory already has its signed report filed + auto-
         emailed (or the agency has that off, in which case this Resend
         button is the only send path). Download reuses the agency-level
         report route; the confirm popover lists real recipients (straight
         off distributionRecipients(), server-computed) before sending. --}}
    @if($inventory->status === \App\Models\RentalInventory::STATUS_COMPLETED)
        <div class="flex items-center gap-2 flex-wrap">
            <a href="{{ route('corex.rental-inventories.report', $inventory) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">
                Download report
            </a>
            @if($inventory->publicShareUrl())
                <button type="button" @click="navigator.clipboard.writeText(shareUrl).then(() => { copiedShareLink = true; setTimeout(() => copiedShareLink = false, 2000); })"
                        class="corex-btn-outline text-xs">
                    <span x-text="copiedShareLink ? 'Copied!' : 'Copy share link'"></span>
                </button>
                <a href="https://wa.me/?text={{ urlencode('Inventory report: ' . $inventory->publicShareUrl()) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs" style="text-decoration:none;">
                    WhatsApp
                </a>
            @endif
            <div class="relative">
                <button type="button" @click="resendOpen = !resendOpen; resendResult = null; resendError = '';" class="corex-btn-outline text-xs">
                    Resend report
                </button>
                <div x-show="resendOpen" x-cloak @click.outside="resendOpen = false"
                     style="position:absolute; top:100%; left:0; margin-top:4px; width:22rem; z-index:30; background:var(--surface); border:1px solid var(--border); border-radius:8px; box-shadow:0 8px 30px rgba(0,0,0,0.18);"
                     class="p-3">
                    <template x-if="!resendResult">
                        <div class="space-y-2">
                            <p class="text-xs font-semibold" style="color:var(--text-primary);">Send the signed report to:</p>
                            <ul class="text-xs space-y-0.5" style="color:var(--text-secondary);">
                                <template x-for="r in reportRecipients" :key="r.email">
                                    <li x-text="r.name + ' (' + r.role + ') — ' + r.email"></li>
                                </template>
                            </ul>
                            <p x-show="!reportRecipients.length" class="text-xs" style="color:var(--ds-crimson);">No recipient has an email on file.</p>
                            <p x-show="resendError" x-text="resendError" class="text-xs" style="color:var(--ds-crimson);"></p>
                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" @click="resendOpen = false" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="color:var(--text-secondary);">Cancel</button>
                                <button type="button" :disabled="resendBusy || !reportRecipients.length" @click="sendReportResend()"
                                        class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">
                                    <span x-text="resendBusy ? 'Sending…' : 'Confirm & send'"></span>
                                </button>
                            </div>
                        </div>
                    </template>
                    <template x-if="resendResult">
                        <div class="space-y-1">
                            <template x-for="r in resendResult" :key="r.email">
                                <p class="text-xs" :style="r.status === 'sent' ? 'color:var(--ds-green,#059669);' : 'color:var(--ds-crimson);'" x-text="(r.status === 'sent' ? '✓ ' : '✗ ') + r.email"></p>
                            </template>
                            <div class="flex justify-end pt-1">
                                <button type="button" @click="resendOpen = false; resendResult = null;" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Close</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); color: var(--ds-green,#059669);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Header: owner/tenants — display only, pulled from the same relations §17's header block already uses. --}}
    <div class="rounded-md p-4 grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
        <div><span style="color: var(--text-muted);">{{ $ownerPartyLabel }}:</span> {{ optional($inventory->property?->sellerOwnerContact())->full_name ?? '—' }}</div>
        @foreach($inventory->lease?->tenants ?? [] as $idx => $tenant)
            <div><span style="color: var(--text-muted);">Tenant {{ $idx + 1 }}:</span> {{ $tenant->contact?->full_name ?? '—' }}</div>
        @endforeach
        <div><span style="color: var(--text-muted);">Inspection done by:</span> {{ $inventory->createdBy?->name ?? '—' }}</div>
    </div>

    {{-- Lines, grouped by room — quantity + free-text description, per Johan's real document. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Items</h2>

        {{-- Every real space the property has, whatever its state — the
             SAME PropertyRoom source/order the capture screen and
             unvisitedRooms() (§12) already use. A room shows its items, its
             "Nothing in this room" mark, or "Not checked" — never simply
             absent because it happens to have zero lines (the bug Johan
             found on inventory 8/property 5294: only Bedroom 1 rendered). --}}
        @forelse($rooms as $room)
            @php $roomLines = $linesByRoomId->get($room->id, collect()); @endphp
            <div class="space-y-1">
                <h3 class="text-xs font-bold uppercase tracking-wide" style="color: var(--text-secondary);">{{ $room->label }}</h3>
                @forelse($roomLines as $line)
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
                @empty
                    @if($markedEmptyRoomIds->has($room->id))
                        <p class="text-xs" style="color: var(--ds-green,#16a34a);">&#10003; Nothing in this room</p>
                    @else
                        <p class="text-xs" style="color: var(--text-muted);">Not checked</p>
                    @endif
                @endforelse
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">This property has no spaces set up yet.</p>
        @endforelse

        {{-- Legacy/free-text lines with no property_room_id — pre-§0b data,
             or a line added via this page's own "Add item" form below
             (which posts a bare room_label, not a real room). Kept visible,
             never silently dropped just because they don't match a real
             PropertyRoom. --}}
        @foreach($legacyLineGroups as $roomLabel => $lines)
            <div class="space-y-1">
                <h3 class="text-xs font-bold uppercase tracking-wide" style="color: var(--text-secondary);">{{ $roomLabel }}</h3>
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
        @endforeach

        @permission('rental_inventories.create')
        @if(!in_array($inventory->status, ['completed', 'cancelled']))
        <form method="POST" action="{{ route('corex.rental-inventories.lines.store', $inventory) }}" class="grid grid-cols-1 sm:grid-cols-6 gap-2 pt-2" style="border-top: 1px solid var(--border);">
            @csrf
            <input type="text" name="room_label" required placeholder="Room (e.g. Lounge)" list="room-labels" class="prop-input sm:col-span-2">
            <datalist id="room-labels">
                @foreach($rooms->pluck('label')->merge($legacyLineGroups->keys())->unique() as $room)<option value="{{ $room }}"></option>@endforeach
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
                    'landlord' => $ownerPartyLabel . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
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
            {{-- Johan, 2026-09-28 — "shows the signatures block TWICE (once
                 with dates, once without)." A party who already has a
                 disposition has their FULL record (date, signature image,
                 refusal reason/note) in the read-only "Signatures" list
                 above — this recording section is ACTION-only now, so a
                 dispositioned party's whole row simply doesn't render here
                 a second time, rather than repeating a compact "Signed"/
                 "Refused" summary. _save() reloads the page on success
                 (matching completeInventory()'s own behaviour), so the row
                 disappearing here and appearing above happen in the same
                 reload, never out of sync. --}}
            @foreach($inventory->lease?->tenants ?? [] as $tenant)
                <template x-if="!dispositionFor('tenant', {{ $tenant->contact_id }})">
                    <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm" x-text="tenantName({{ $tenant->contact_id }}, '{{ addslashes($tenant->contact?->full_name ?? 'Tenant') }}')"></span>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="openSigningFor('tenant_{{ $tenant->contact_id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Sign</button>
                                <button type="button" @click="openRefusalFor('tenant_{{ $tenant->contact_id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Refuses</button>
                            </div>
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
                </template>
            @endforeach

            @if($landlordContact = $inventory->property?->sellerOwnerContact())
                <template x-if="!dispositionFor('landlord', {{ $landlordContact->id }})">
                    <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm">{{ $landlordContact->full_name }} ({{ $ownerPartyLabel }})</span>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="openSigningFor('landlord_{{ $landlordContact->id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Sign</button>
                                <button type="button" @click="openRefusalFor('landlord_{{ $landlordContact->id }}')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">Refuses</button>
                            </div>
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
                </template>
            @else
                <p class="text-xs" style="color: var(--text-muted);">Landlord: not linked to this property — nothing to sign.</p>
            @endif

            <template x-if="!dispositionFor('agent', null)">
                <div class="py-1.5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold">Agent</span>
                        <template x-if="allRequiredPartiesDispositioned">
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
            </template>

            <div x-show="lifecycleError" x-cloak class="text-xs" style="color:#ef4444;">
                <p x-text="lifecycleError"></p>
                {{-- Johan, 2026-09-28 — each unchecked room named, clickable
                     to the capture screen's own panel for that space
                     (scrollToRoom() there reads the exact same room-panel-N
                     id this href targets). --}}
                <template x-if="unvisitedRooms.length">
                    <ul class="mt-1 space-y-0.5" style="list-style: disc; padding-left: 1.25rem;">
                        <template x-for="room in unvisitedRooms" :key="room.id">
                            <li><a :href="captureUrlFor(room.id)" class="underline" style="color:#ef4444;" x-text="room.label"></a></li>
                        </template>
                    </ul>
                </template>
            </div>
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

@php
    // Built here, not inline inside @json() below: Blade's @json compiler
    // splits its raw argument text on EVERY top-level comma (it assumes
    // @json($value, $options, $depth)), so any array literal with more
    // than one key corrupts the compiled statement. A PHP variable holding
    // the finished array has zero top-level commas in the @json() call
    // itself, which is always safe regardless of how many keys it has.
    $signaturesForJs = $inventory->signatures->map(fn($s) => [
        'party_role' => $s->party_role,
        'party_contact_id' => $s->party_contact_id,
        'disposition' => $s->disposition,
    ]);
@endphp
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
function rentalInventoryShow(inventoryId) {
    return {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        baseUrl: `/corex/rental-inventories/${inventoryId}`,
        signatures: @json($signaturesForJs),
        landlordContactId: {{ $inventory->property?->sellerOwnerContact()?->id ?? 'null' }},
        tenantContactIds: @json(($inventory->lease?->tenants ?? collect())->pluck('contact_id')),

        activeSigningKey: null,
        activeRefusalKey: null,
        signaturePads: {},
        refusalForm: {},

        // §41-follow-up (Job 3) — the "Resend report" popover's own state.
        // reportRecipients/shareUrl are server-computed (distributionRecipients()/
        // publicShareUrl(), the same contract the distribution service itself
        // reads) — never re-derived client-side.
        reportRecipients: @json($reportRecipients ?? []),
        shareUrl: {{ Js::from($inventory->publicShareUrl() ?? '') }},
        resendOpen: false,
        resendBusy: false,
        resendError: '',
        resendResult: null,
        copiedShareLink: false,
        async sendReportResend() {
            this.resendBusy = true;
            this.resendError = '';
            try {
                const res = await fetch(`${this.baseUrl}/resend-report`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });
                if (!res.ok) { const j = await res.json().catch(() => ({})); throw new Error(j.message || `Request failed (${res.status}).`); }
                const data = await res.json();
                this.resendResult = data.results;
            } catch (e) { this.resendError = e.message; }
            finally { this.resendBusy = false; }
        },
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
                // Johan, 2026-09-28 — reload so the read-only "Signatures"
                // list above picks up this party's full record (date,
                // signature image, refusal reason/note) in the SAME reload
                // that removes their now-redundant row from this recording
                // section (dispositionFor() re-evaluates against a fresh
                // page load's own $signaturesForJs) — the two can never
                // show conflicting/duplicate state even for a moment.
                window.location.reload();
            } catch (e) { this.lifecycleError = e.message; }
        },
        // Johan, 2026-09-28 — "the red completion warning must list the
        // unchecked rooms by name, each clickable to jump to that space."
        // unvisitedRooms holds the server's structured {id, label} list
        // (RentalInventoryUnvisitedRoomsException) when that's the specific
        // reason completion was refused; empty for any other refusal
        // (nothing recorded yet, outstanding signature, etc.) so the
        // markup below only ever renders links when they mean something.
        unvisitedRooms: [],
        captureUrlFor(roomId) {
            return {{ Js::from(route('corex.properties.inventory.show', $inventory->property_id)) }} + '#room-panel-' + roomId;
        },
        async completeInventory() {
            this.lifecycleError = '';
            this.unvisitedRooms = [];
            try {
                const res = await fetch(`${this.baseUrl}/complete`, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } });
                if (!res.ok) {
                    const j = await res.json().catch(() => ({}));
                    this.unvisitedRooms = j.unvisited_rooms || [];
                    throw new Error(j.message || `Request failed (${res.status}).`);
                }
                window.location.reload();
            } catch (e) { this.lifecycleError = e.message; }
        },
    };
}
</script>
@endpush
