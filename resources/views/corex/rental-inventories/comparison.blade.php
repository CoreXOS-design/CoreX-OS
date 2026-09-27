@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §8/§14 — the move-out review. Read-time
    only: RentalInventoryComparisonService computes this fresh on every
    load, nothing here is stored. A PROPOSAL an agent reviews — no currency,
    no deposit figure anywhere on this page.

    §14, 2026-09-27 — rebuilt to Johan's approved mockup: always anchored on
    the ORIGINAL move-in record (never a chain — see the service's own
    docblock for why this can't repeat rental-inspections' "only the
    immediately-previous record" mistake), quantities on BOTH sides of one
    line, disposition as its own four-outcome vocabulary (not a condition),
    unchanged lines collapsed to one grey line, an "Only differences"
    filter, and the current side's own photo evidence shown even when
    there isn't any yet.

    Switched from a <table> to a card/list layout in this pass: a table
    cell can't cleanly hold a photo strip AND collapse to one line
    depending on the row's own state — a list of blocks can.
--}}

@section('content')
<div class="p-6 max-w-5xl mx-auto space-y-4" x-data="rentalInventoryComparison({{ $inventory->id }})">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-lg font-semibold">Move-out comparison — {{ $inventory->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">Always against the ORIGINAL move-in record. Nothing on this page is a deposit figure — it is what was found, for an agent to review.</p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            {{-- §14 — "that is the view an agent hands a tenant." Client-side
                 only (the data is already fully rendered; no extra request
                 needed to filter what's already on the page). --}}
            <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                <input type="checkbox" x-model="onlyDifferences">
                Only differences
            </label>
            <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="corex-btn-outline text-xs">Back to inventory</a>
        </div>
    </div>

    <div x-show="lifecycleError" x-cloak class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" x-text="lifecycleError"></div>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border); overflow:hidden;">
        @forelse($rows as $row)
            @php
                // §14 — the two visual shapes this screen has: a genuinely
                // unchanged line collapses to one grey line, full stop.
                // Everything else — a real difference OR a line nobody has
                // checked yet — gets the full row, because both are states
                // an agent might still act on (record a finding, or take a
                // photo before it's too late to).
                $isUnchanged = $row['unchanged'];
                $badgeClass = match ($row['disposition_key']) {
                    'present' => 'ds-badge-success',
                    'short' => 'ds-badge-warning',
                    'damaged' => 'ds-badge-orange',
                    'missing' => 'ds-badge-danger',
                    default => 'ds-badge-info',
                };
            @endphp
            <div class="px-4 py-3" style="border-bottom:1px solid var(--border);"
                 x-show="!onlyDifferences || {{ $isUnchanged ? 'false' : 'true' }}"
                 data-qa="comparison-row-{{ $row['line_id'] }}">
                @if($isUnchanged)
                    {{-- §14 — "unchanged lines collapse to one grey line
                         reading 'no change'. The differences carry the
                         colour." Deliberately no photos, no notes, no
                         recorded-by here — there is nothing to argue about
                         on this line, so nothing else earns space on
                         screen. Still correctable: the same action a
                         real-difference row has. --}}
                    <div class="flex items-center justify-between gap-2 text-xs" style="color: var(--text-muted);">
                        <span>
                            {{ $row['room_label'] }} — {{ $row['description'] }}
                            ({{ $row['quantity_at_move_in'] }} at move-in, {{ $row['quantity_found'] ?? $row['quantity_at_move_in'] }} today) — no change
                        </span>
                        @permission('rental_inventories.create')
                        <button type="button" @click="openFor({{ $row['line_id'] }})" tabindex="-1" class="text-xs font-semibold px-2 py-1 rounded-md shrink-0" style="background:var(--surface-2); color:var(--text-secondary);">Correct</button>
                        @endpermission
                    </div>
                @else
                    <div class="space-y-2">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs" style="color: var(--text-muted);">{{ $row['room_label'] }}</p>
                                <p class="text-sm font-semibold">{{ $row['description'] }}</p>
                            </div>
                            @permission('rental_inventories.create')
                            <button type="button" @click="openFor({{ $row['line_id'] }})" class="text-xs font-semibold px-3 py-1.5 rounded-md shrink-0" style="background:var(--surface-2);">
                                {{ $row['outstanding'] ? 'Record' : 'Correct' }}
                            </button>
                            @endpermission
                        </div>

                        {{-- §14, Johan's own wording, verbatim — "4 at
                             move-in, 2 today" is the sentence this module
                             exists to produce. Rendered as that literal
                             sentence, plain text, no inner tags splitting
                             it up — the SAME phrasing the collapsed "no
                             change" row above uses, so the two row shapes
                             read as one consistent voice. Quantities on
                             BOTH sides of one line, always, not just when
                             something's wrong. --}}
                        <div class="text-sm font-semibold">{{ $row['quantity_at_move_in'] }} at move-in, {{ $row['quantity_found'] ?? '—' }} today</div>

                        @if($row['outstanding'])
                            <span class="ds-badge ds-badge-muted text-xs">Not yet checked</span>
                        @else
                            <div class="text-xs space-y-0.5">
                                <span class="ds-badge {{ $badgeClass }}">{{ $row['disposition_label'] }}</span>
                                @if($row['notes'])
                                    <div style="color: var(--text-muted);">{{ $row['notes'] }}</div>
                                @endif
                                <div style="color: var(--text-muted);">{{ $row['recorded_by'] }} — {{ $row['recorded_at'] }}</div>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                            {{-- Move-in evidence — §11.8/§13, unchanged shape,
                                 fixed-size thumbnails, not the capture
                                 surface's own gallery/tagger (a line worth
                                 photographing is rarely photographed more
                                 than a couple of times). --}}
                            <div>
                                <p class="text-[11px] font-semibold uppercase" style="color: var(--text-muted);">Move-in</p>
                                @if(count($row['photos']))
                                    <div class="flex items-center gap-1 pt-1 flex-wrap">
                                        @foreach($row['photos'] as $photo)
                                            <a href="{{ $photo['storage_path'] }}" target="_blank" rel="noopener" title="Move-in photo">
                                                <img src="{{ $photo['storage_path'] }}" alt="Move-in photo" class="rounded-md object-cover" style="width:44px; height:44px; border:1px solid var(--border);">
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-xs pt-1" style="color: var(--text-muted);">No move-in photo on record.</p>
                                @endif
                            </div>

                            {{-- §14, Johan's own wording — "Where there is no
                                 photo on the current side, SHOW that rather
                                 than hiding it. A claim with no photo is a
                                 weak claim and the agent should see it while
                                 they can still take one." The upload control
                                 sits right here, on the SAME row, so taking
                                 that photo is one tap away, not a trip to a
                                 different screen. Immediate upload, no
                                 staging — same rule as everywhere else this
                                 session; a full reload after success is the
                                 SAME pattern this page's own disposition Save
                                 already uses, not a new mechanism. --}}
                            <div>
                                <p class="text-[11px] font-semibold uppercase" style="color: var(--text-muted);">Today</p>
                                @if(count($row['move_out_photos']))
                                    <div class="flex items-center gap-1 pt-1 flex-wrap">
                                        @foreach($row['move_out_photos'] as $photo)
                                            <a href="{{ $photo['storage_path'] }}" target="_blank" rel="noopener" title="Move-out photo">
                                                <img src="{{ $photo['storage_path'] }}" alt="Move-out photo" class="rounded-md object-cover" style="width:44px; height:44px; border:1px solid var(--border);">
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-xs pt-1 font-semibold" style="color: var(--ds-crimson,#c41e3a);">No photo taken yet.</p>
                                @endif
                                @permission('rental_inventories.create')
                                <label class="text-xs font-semibold mt-1 inline-flex items-center gap-1 px-2 py-1 rounded-md cursor-pointer" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                                       :class="{ 'opacity-50 pointer-events-none': photoBusy[{{ $row['line_id'] }}] }">
                                    <span>&#128247;</span>
                                    <span x-text="photoBusy[{{ $row['line_id'] }}] ? 'Uploading…' : 'Add photo'"></span>
                                    <input type="file" accept="image/*,.heic,.heif" multiple class="hidden"
                                           @change="uploadMoveOutPhoto({{ $row['line_id'] }}, $event.target.files); $event.target.value=''">
                                </label>
                                @endpermission
                            </div>
                        </div>
                    </div>
                @endif

                {{-- The record/correct panel — reachable from BOTH the
                     collapsed and full row shapes above. --}}
                <div x-show="activeLine === {{ $row['line_id'] }}" x-cloak class="mt-2 p-3 rounded-md" style="background:var(--surface-2);">
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
                        <select x-model="field({{ $row['line_id'] }}).disposition_key" class="prop-input">
                            <option value="">Select…</option>
                            @foreach($dispositionPresets as $preset)
                                <option value="{{ $preset['key'] }}">{{ $preset['label'] }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="0" x-model.number="field({{ $row['line_id'] }}).quantity_found" placeholder="Qty found (leave blank if not applicable)" class="prop-input sm:col-span-1">
                        <input type="text" x-model="field({{ $row['line_id'] }}).notes" placeholder="Note" class="prop-input sm:col-span-1">
                        <div class="flex gap-2">
                            <button type="button" @click="activeLine = null" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface);">Cancel</button>
                            <button type="button" @click="save({{ $row['line_id'] }})" :disabled="!field({{ $row['line_id'] }}).disposition_key" class="text-xs px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save</button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm p-4" style="color: var(--text-muted);">This inventory has no items to compare.</p>
        @endforelse
    </div>
</div>
@endsection

@push('scripts')
<script>
function rentalInventoryComparison(inventoryId) {
    return {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        baseUrl: `/corex/rental-inventories/${inventoryId}`,
        activeLine: null,
        forms: {},
        lifecycleError: '',
        // §14 — "that is the view an agent hands a tenant."
        onlyDifferences: false,
        photoBusy: {},
        field(lineId) { return this.forms[lineId] || (this.forms[lineId] = { disposition_key: '', quantity_found: null, notes: '' }); },
        openFor(lineId) { this.activeLine = this.activeLine === lineId ? null : lineId; },
        async save(lineId) {
            const form = this.field(lineId);
            this.lifecycleError = '';
            try {
                const res = await fetch(`${this.baseUrl}/lines/${lineId}/dispositions`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        disposition_key: form.disposition_key,
                        quantity_found: form.quantity_found === '' ? null : form.quantity_found,
                        notes: form.notes || null,
                    }),
                });
                if (!res.ok) { const j = await res.json().catch(() => ({})); throw new Error(j.message || `Request failed (${res.status}).`); }
                window.location.reload();
            } catch (e) { this.lifecycleError = e.message; }
        },
        // §14 — uploads THE MOMENT a file is picked, no staging. Reloads on
        // success so the page's own server-rendered move_out_photos (and,
        // if this was the row's first evidence, its "no photo taken"
        // warning) reflect reality immediately — the SAME pattern save()
        // above already uses on this exact page, not a second mechanism
        // built for photos specifically.
        async uploadMoveOutPhoto(lineId, files) {
            const fileList = Array.from(files || []);
            if (!fileList.length) return;
            this.photoBusy[lineId] = true;
            this.lifecycleError = '';
            try {
                const fd = new FormData();
                fileList.forEach(f => fd.append('photos[]', f));
                fileList.forEach(() => fd.append('client_idempotency_keys[]', (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()))));
                const res = await fetch(`${this.baseUrl}/lines/${lineId}/move-out-photos`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                    body: fd,
                });
                if (!res.ok) { const j = await res.json().catch(() => ({})); throw new Error(j.message || `Upload failed (${res.status}).`); }
                window.location.reload();
            } catch (e) {
                this.lifecycleError = e.message;
                this.photoBusy[lineId] = false;
            }
        },
    };
}
</script>
@endpush
