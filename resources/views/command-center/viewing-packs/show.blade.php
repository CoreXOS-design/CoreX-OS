{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-6">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $pack->title ?: ('Viewing Pack #' . $pack->id) }}</h1>
                <p class="text-sm text-white/60">
                    Buyer: {{ optional($pack->contact)->full_name ?? '—' }}
                    · Agent: {{ optional($pack->agent)->name ?? '—' }}
                    · Status: {{ ucfirst($pack->status) }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('corex.viewing-packs.index') }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">&larr; All packs</a>
                @if(optional($pack->contact))
                    <a href="{{ route('command-center.buyers.show', $pack->contact_id) }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">Open buyer</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- LEFT: selection (selected list + Core Matches + ad-hoc search) --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- Selected properties — drag to set the viewing order (manual; no auto-routing) --}}
            @php
                $orderedItems = $pack->viewingPackProperties->map(fn ($vpp) => [
                    'id'     => $vpp->id,
                    'label'  => optional($vpp->property)->address ?: ('Property #' . $vpp->property_id),
                    'source' => str_replace('_', ' ', $vpp->source),
                    'docs'   => $vpp->viewingPackDocuments->count(),
                ])->values();
                $removeBase = url('corex/viewing-packs/' . $pack->id . '/properties');
            @endphp
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);"
                 x-data="viewingPackOrder(@js($orderedItems), '{{ route('corex.viewing-packs.properties.reorder', $pack) }}', '{{ $removeBase }}', '{{ csrf_token() }}')">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Selected properties</h3>
                    <span class="text-xs" style="color: var(--text-muted);"><span x-text="items.length"></span> selected</span>
                </div>

                <template x-if="items.length === 0">
                    <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface-2); border: 1px dashed var(--border);">
                        <p class="text-sm font-medium" style="color: var(--text-secondary);">No properties selected yet.</p>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Add from Core Matches below, or search any property.</p>
                    </div>
                </template>

                <ol class="space-y-2" x-show="items.length > 0">
                    <template x-for="(item, idx) in items" :key="item.id">
                        <li class="flex items-center gap-3 rounded-md px-3 py-2"
                            style="background: var(--surface-2); border: 1px solid var(--border);"
                            draggable="true"
                            @dragstart="dragStart($event, idx)"
                            @dragover.prevent="dragOver($event, idx)"
                            @drop.prevent="drop($event, idx)"
                            @dragend="dragEnd()"
                            :style="dragIdx === idx ? 'background: var(--surface-2); border: 1px solid var(--brand-icon); opacity:0.6;' : 'background: var(--surface-2); border: 1px solid var(--border);'">
                            <span class="cursor-move select-none text-base" title="Drag to reorder" style="color: var(--text-muted);">⠿</span>
                            <span class="text-sm font-semibold w-5 text-right" style="color: var(--text-muted);" x-text="(idx + 1) + '.'"></span>
                            <span class="flex-1 text-sm" style="color: var(--text-primary);" x-text="item.label"></span>
                            <span class="ds-badge ds-badge-default" title="How this property entered the pack" x-text="item.source"></span>
                            <span class="text-xs" style="color: var(--text-muted);" x-text="item.docs + ' docs'"></span>
                            <form method="POST" :action="removeBase + '/' + item.id"
                                  @submit="return confirm('Remove this property from the pack?');">
                                @csrf
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Remove</button>
                            </form>
                        </li>
                    </template>
                </ol>

                <p class="mt-3 text-xs" style="color: var(--text-muted);">Drag rows to set the viewing order. This sequence becomes the page order in both PDFs. Document selection and the PDFs arrive in the next steps.</p>
            </div>

            {{-- Core Matches (canonical engine) --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-lg font-semibold mb-1" style="color: var(--text-primary);">Core Matches</h3>
                <p class="text-xs mb-3" style="color: var(--text-muted);">The buyer's matched properties, scored by the canonical match engine.</p>

                @if($coreMatches->isEmpty())
                    <div class="rounded-md py-6 px-6 text-center" style="background: var(--surface-2); border: 1px dashed var(--border);">
                        <p class="text-sm" style="color: var(--text-secondary);">No Core Matches for this buyer.</p>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Use the search below to add properties ad-hoc.</p>
                    </div>
                @else
                    <ul class="space-y-2">
                        @foreach($coreMatches as $cm)
                            @php $alreadyIn = in_array($cm->id, $selectedIds); @endphp
                            <li class="flex items-center gap-3 rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <span class="flex-1 text-sm" style="color: var(--text-primary);">{{ $cm->address ?: ('Property #' . $cm->id) }}{{ $cm->suburb ? ' — ' . $cm->suburb : '' }}</span>
                                @if(!is_null($cm->match_score))
                                    <span class="ds-badge ds-badge-success" title="Canonical match score">{{ (int) $cm->match_score }}%</span>
                                @endif
                                @if($alreadyIn)
                                    <span class="text-xs" style="color: var(--text-muted);">Added</span>
                                @else
                                    <form method="POST" action="{{ route('corex.viewing-packs.properties.add', $pack) }}">
                                        @csrf
                                        <input type="hidden" name="property_id" value="{{ $cm->id }}">
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Add</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Ad-hoc search --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);"
                 x-data="adhocPropertySearch('{{ route('corex.viewing-packs.properties.search', $pack) }}')">
                <h3 class="text-lg font-semibold mb-1" style="color: var(--text-primary);">Add any property</h3>
                <p class="text-xs mb-3" style="color: var(--text-muted);">Search by address, suburb or reference. Properties that aren't a Core Match are still added — the system notes the miss silently.</p>

                <input type="text" x-model="q" @input.debounce.300ms="search()" placeholder="Start typing an address, suburb or ref…"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">

                <ul class="mt-2 space-y-2" x-show="results.length" x-cloak>
                    <template x-for="r in results" :key="r.id">
                        <li class="flex items-center gap-3 rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <span class="flex-1 text-sm" style="color: var(--text-primary);" x-text="r.label"></span>
                            <form method="POST" action="{{ route('corex.viewing-packs.properties.add', $pack) }}">
                                @csrf
                                <input type="hidden" name="property_id" :value="r.id">
                                <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Add</button>
                            </form>
                        </li>
                    </template>
                </ul>
                <p class="mt-2 text-xs" x-show="searched && !results.length" x-cloak style="color: var(--text-muted);">No properties found.</p>
            </div>

            {{-- Buyer-pack documents — per property, ONLY buyer-pack-eligible attached docs (Step 5a) --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-lg font-semibold mb-1" style="color: var(--text-primary);">Buyer-pack documents</h3>
                <p class="text-xs mb-3" style="color: var(--text-muted);">Only documents whose type is eligible for the buyer pack are shown. Identity / compliance documents never appear here. Documents are optional.</p>

                @if($docPanel->isEmpty())
                    <div class="rounded-md py-6 px-6 text-center" style="background: var(--surface-2); border: 1px dashed var(--border);">
                        <p class="text-sm" style="color: var(--text-secondary);">Add properties first — document selection appears per property.</p>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($docPanel as $entry)
                            @php
                                $vpp       = $entry['vpp'];
                                $eligible  = $entry['eligible'];
                                $selDocIds = $entry['selectedIds'];
                                $vpdByDoc  = $vpp->viewingPackDocuments->keyBy('document_id');
                                $addr      = optional($vpp->property)->address ?: ('Property #' . $vpp->property_id);
                            @endphp
                            <div class="rounded-md p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-sm font-semibold" style="color: var(--text-muted);">{{ $vpp->sort_order }}.</span>
                                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $addr }}</span>
                                </div>

                                @if($eligible->isEmpty())
                                    <p class="text-xs" style="color: var(--text-muted);">No buyer-eligible documents attached to this property or the buyer.</p>
                                @else
                                    <ul class="space-y-1.5">
                                        @foreach($eligible as $doc)
                                            @php
                                                $isIn  = in_array($doc->id, $selDocIds);
                                                $label = ($doc->documentType?->label ?: $doc->documentType?->slug ?: 'Document')
                                                       . ' — ' . ($doc->original_name ?? ('Doc #' . $doc->id));
                                            @endphp
                                            <li class="flex items-center gap-3 rounded-md px-3 py-1.5" style="background: var(--surface); border: 1px solid var(--border);">
                                                <span class="flex-1 text-sm" style="color: var(--text-primary);">{{ $label }}</span>
                                                @if($isIn)
                                                    <span class="ds-badge ds-badge-success" title="Included in the buyer pack">Included</span>
                                                    <form method="POST" action="{{ route('corex.viewing-packs.properties.documents.remove', [$pack, $vpp, $vpdByDoc[$doc->id]]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Remove</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('corex.viewing-packs.properties.documents.add', [$pack, $vpp]) }}">
                                                        @csrf
                                                        <input type="hidden" name="document_id" value="{{ $doc->id }}">
                                                        <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Add</button>
                                                    </form>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- RIGHT: pack settings (title + status) --}}
        <div class="lg:col-span-1 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Pack details</h3>
            <form method="POST" action="{{ route('corex.viewing-packs.update', $pack) }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div>
                    <label for="vp-title" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Title</label>
                    <input id="vp-title" type="text" name="title" value="{{ old('title', $pack->title) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label for="vp-status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                    <select id="vp-status" name="status" class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        @foreach(\App\Models\ViewingPack::STATUSES as $s)
                            <option value="{{ $s }}" @selected($pack->status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="corex-btn-primary w-full">Save</button>
            </form>

            <hr class="my-4" style="border-color: var(--border);">

            <form method="POST" action="{{ route('corex.viewing-packs.destroy', $pack) }}"
                  onsubmit="return confirm('Archive this viewing pack? You can recover it later.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="corex-btn-outline w-full" style="color: var(--ds-crimson); border-color: var(--ds-crimson);">Archive pack</button>
            </form>
        </div>
    </div>
</div>

<script>
function viewingPackOrder(items, reorderUrl, removeBase, csrf) {
    return {
        items: items,
        removeBase: removeBase,
        dragIdx: null,
        dragStart(e, idx) {
            this.dragIdx = idx;
            e.dataTransfer.effectAllowed = 'move';
        },
        dragOver(e, idx) {
            if (this.dragIdx === null || this.dragIdx === idx) return;
            const moved = this.items.splice(this.dragIdx, 1)[0];
            this.items.splice(idx, 0, moved);
            this.dragIdx = idx; // sequence numbers (idx+1) update reactively
        },
        async drop(e, idx) {
            if (this.dragIdx === null) return;
            this.dragIdx = null;
            const order = this.items.map(it => it.id);
            try {
                await fetch(reorderUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ order }),
                });
            } catch (err) {
                // Order persists on next successful drop; reload reflects the server truth.
            }
        },
        dragEnd() { this.dragIdx = null; },
    };
}

function adhocPropertySearch(searchUrl) {
    return {
        q: '',
        results: [],
        searched: false,
        async search() {
            const term = this.q.trim();
            if (term.length < 2) { this.results = []; this.searched = false; return; }
            try {
                const res = await fetch(searchUrl + '?q=' + encodeURIComponent(term), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                this.results = res.ok ? await res.json() : [];
            } catch (e) {
                this.results = [];
            }
            this.searched = true;
        },
    };
}
</script>
@endsection
