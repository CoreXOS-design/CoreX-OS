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
                {{-- Two SEPARATE files, two SEPARATE buttons (compliance spine §1) — never a combined download. --}}
                @if($pack->viewingPackProperties->isNotEmpty())
                    <a href="{{ route('corex.viewing-packs.buyer-pack', $pack) }}" class="corex-btn-primary no-underline" target="_blank" rel="noopener">Download Buyer Pack</a>
                    <a href="{{ route('corex.viewing-packs.agent-sheet', $pack) }}" class="corex-btn-outline no-underline"
                       target="_blank" rel="noopener"
                       style="color:#fff; border-color:#b91c1c; background:#b91c1c;"
                       title="Confidential — agent eyes only. A separate file from the buyer pack; never hand this to the buyer.">Download Agent Sheet 🔒</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    {{-- AT-109 — Selected-properties data (rendered in the sticky right column). --}}
    @php
        $orderedItems = $pack->viewingPackProperties->map(fn ($vpp) => [
            'id'     => $vpp->id,
            'label'  => optional($vpp->property)->address ?: ('Property #' . $vpp->property_id),
            'source' => str_replace('_', ' ', $vpp->source),
            'docs'   => $vpp->viewingPackDocuments->count(),
        ])->values();
        $removeBase = url('corex/viewing-packs/' . $pack->id . '/properties');
    @endphp

    {{-- AT-110 Bug 3 — in-place region. Add/remove/redact swap THIS node's innerHTML
         (vpAction/vpSwapContent) instead of a full reload, so scroll is preserved. --}}
    <div id="vp-content" class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">

        {{-- LEFT: selection (Core Matches w/ search·filter·sort + ad-hoc search + docs) --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- Core Matches (canonical engine) — AT-109 search · filter · sort over
                 the buyer's already-loaded canonical match set ($coreMatches). All
                 client-side (the set is small); selection/Add logic is unchanged. --}}
            @php
                $cmData = $coreMatches->map(fn ($cm) => [
                    'id'      => $cm->id,
                    'address' => $cm->address ?: ('Property #' . $cm->id),
                    'suburb'  => (string) ($cm->suburb ?? ''),
                    'price'   => (int) ($cm->price ?? 0),
                    'score'   => (int) ($cm->match_score ?? 0),
                    'ref'     => (string) ($cm->external_id ?: ('REF ' . $cm->id)),
                    'added'   => in_array($cm->id, $selectedIds),
                ])->values();
                $cmSuburbs   = $coreMatches->pluck('suburb')->filter()->unique()->sort()->values();
                $cmScoreFloor = (int) \App\Services\Matching\MatchingService::MIN_SCORE_TO_DISPLAY;
                $cmMinPrice  = (int) ($coreMatches->min('price') ?? 0);
                $cmMaxPrice  = (int) ($coreMatches->max('price') ?? 0);
            @endphp
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);"
                 x-data="coreMatchesUx(@js($cmData), {{ $cmScoreFloor }})">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Core Matches</h3>
                    <span class="text-xs" style="color: var(--text-muted);">showing <span x-text="filtered().length"></span> of {{ $coreMatches->count() }}</span>
                </div>
                <p class="text-xs mb-3" style="color: var(--text-muted);">The buyer's matched properties, scored by the canonical match engine.</p>

                @if($coreMatches->isEmpty())
                    <div class="rounded-md py-6 px-6 text-center" style="background: var(--surface-2); border: 1px dashed var(--border);">
                        <p class="text-sm" style="color: var(--text-secondary);">No Core Matches for this buyer.</p>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Use the search below to add properties ad-hoc.</p>
                    </div>
                @else
                    {{-- Toolbar: search · suburb filter · price band · match% · sort · reset --}}
                    <div class="rounded-md p-3 mb-3 space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" x-model="q" placeholder="Search address, suburb or ref…"
                                   class="flex-1 min-w-[160px] rounded-md px-3 py-1.5 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <select x-model="sortBy" class="rounded-md px-2 py-1.5 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="score">Sort: Match % (high→low)</option>
                                <option value="price_asc">Sort: Price (low→high)</option>
                                <option value="price_desc">Sort: Price (high→low)</option>
                                <option value="suburb">Sort: Suburb (A–Z)</option>
                                <option value="address">Sort: Address (A–Z)</option>
                            </select>
                            <button type="button" @click="reset()" class="text-xs font-semibold px-2 py-1.5" style="color: var(--ds-crimson);">Reset</button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs" style="color: var(--text-muted);">Price R</span>
                            <input type="number" x-model="priceMin" placeholder="{{ number_format($cmMinPrice) }}" min="0"
                                   class="w-28 rounded-md px-2 py-1 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <span class="text-xs" style="color: var(--text-muted);">to R</span>
                            <input type="number" x-model="priceMax" placeholder="{{ number_format($cmMaxPrice) }}" min="0"
                                   class="w-28 rounded-md px-2 py-1 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <span class="text-xs ml-2" style="color: var(--text-muted);">Match ≥</span>
                            <input type="number" x-model.number="minScore" min="0" max="100" step="5"
                                   class="w-16 rounded-md px-2 py-1 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <span class="text-xs" style="color: var(--text-muted);">%</span>
                        </div>
                        @if($cmSuburbs->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-xs mr-1" style="color: var(--text-muted);">Suburb:</span>
                                @foreach($cmSuburbs as $sub)
                                    <button type="button" @click="toggleSuburb(@js($sub))"
                                            class="text-xs px-2 py-0.5 rounded-md"
                                            :style="selSuburbs.includes(@js($sub))
                                                ? 'background: var(--brand-icon); color:#fff; border:1px solid var(--brand-icon);'
                                                : 'background: var(--surface); color: var(--text-secondary); border:1px solid var(--border);'">{{ $sub }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <ul class="space-y-2">
                        <template x-for="m in filtered()" :key="m.id">
                            {{-- AT-110 Bug 4 — tightened row: address + suburb·price·ref fill the
                                 former dead space; score badge + Add stay right, no stretch gap. --}}
                            <li class="flex items-center gap-3 rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm truncate" style="color: var(--text-primary);" :title="m.address" x-text="m.address"></div>
                                    <div class="text-xs flex items-center gap-1.5 flex-wrap" style="color: var(--text-muted);">
                                        <span x-show="m.suburb" x-text="m.suburb"></span>
                                        <span x-show="m.suburb && m.price">·</span>
                                        <span x-show="m.price" x-text="'R ' + Number(m.price).toLocaleString('en-ZA')"></span>
                                        <span x-show="m.ref" class="opacity-70" x-text="m.ref"></span>
                                    </div>
                                </div>
                                <span class="ds-badge ds-badge-success flex-shrink-0" title="Canonical match score" x-text="m.score + '%'"></span>
                                <template x-if="m.added">
                                    <span class="text-xs flex-shrink-0" style="color: var(--text-muted);">Added</span>
                                </template>
                                <template x-if="!m.added">
                                    <form method="POST" action="{{ route('corex.viewing-packs.properties.add', $pack) }}"
                                          class="flex-shrink-0" @submit.prevent="vpAction($el)">
                                        @csrf
                                        <input type="hidden" name="property_id" :value="m.id">
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Add</button>
                                    </form>
                                </template>
                            </li>
                        </template>
                    </ul>
                    <p class="mt-2 text-xs" x-show="filtered().length === 0" x-cloak style="color: var(--text-muted);">No matches fit these filters. <button type="button" @click="reset()" class="font-semibold" style="color: var(--brand-icon);">Reset</button></p>
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
                            <form method="POST" action="{{ route('corex.viewing-packs.properties.add', $pack) }}" @submit.prevent="vpAction($el)">
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
                                                    @php $vpdRow = $vpdByDoc[$doc->id]; $isRedacted = !empty($vpdRow->redacted_file_path); @endphp
                                                    {{-- AT-110 Bug 1 — HONEST state. "Included" alone is a lie: a doc does
                                                         NOT appear in the buyer pack until it is redacted (the PDF service
                                                         skips any included doc with a NULL redacted artifact). So: green
                                                         "Included ✓" only once redacted; amber "needs redaction" otherwise. --}}
                                                    @if($isRedacted)
                                                        <span class="ds-badge ds-badge-success" title="Redacted and included — this document WILL appear in the buyer pack">Included ✓</span>
                                                        <a href="{{ route('corex.viewing-packs.properties.documents.redacted-file', [$pack, $vpp, $vpdRow]) }}" target="_blank" rel="noopener"
                                                           class="ds-badge ds-badge-default no-underline" title="View the flattened, redacted copy">View redacted</a>
                                                    @else
                                                        <span class="ds-badge"
                                                              style="background: color-mix(in srgb, #f59e0b 15%, transparent); color: #b45309; border: 1px solid color-mix(in srgb, #f59e0b 40%, transparent);"
                                                              title="This document will NOT appear in the buyer pack until you redact it. Click Redact.">Needs redaction</span>
                                                    @endif
                                                    <button type="button"
                                                            class="text-xs font-semibold"
                                                            style="color: var(--brand-icon);"
                                                            @click="$dispatch('open-redactor', {
                                                                dataUrl: '{{ route('corex.viewing-packs.properties.documents.redaction-data', [$pack, $vpp, $vpdRow]) }}',
                                                                postUrl: '{{ route('corex.viewing-packs.properties.documents.redact', [$pack, $vpp, $vpdRow]) }}',
                                                                label: @js($label)
                                                            })">{{ $isRedacted ? 'Re-redact' : 'Redact' }}</button>
                                                    <form method="POST" action="{{ route('corex.viewing-packs.properties.documents.remove', [$pack, $vpp, $vpdRow]) }}"
                                                          @submit.prevent="vpAction($el)">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Remove</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('corex.viewing-packs.properties.documents.add', [$pack, $vpp]) }}"
                                                          @submit.prevent="vpAction($el)">
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

        {{-- RIGHT: sticky column — Selected properties (always visible while picking) + Pack details (AT-109) --}}
        <div class="lg:col-span-1 lg:sticky lg:top-4 self-start space-y-4">

            {{-- Selected properties (relocated here so it stays visible while scrolling the match list) --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);"
                 x-data="viewingPackOrder(@js($orderedItems), '{{ route('corex.viewing-packs.properties.reorder', $pack) }}', '{{ $removeBase }}', '{{ csrf_token() }}')">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Selected properties</h3>
                    <span class="text-xs" style="color: var(--text-muted);"><span x-text="items.length"></span> selected</span>
                </div>

                <template x-if="items.length === 0">
                    <div class="rounded-md py-6 px-4 text-center" style="background: var(--surface-2); border: 1px dashed var(--border);">
                        <p class="text-sm font-medium" style="color: var(--text-secondary);">No properties selected yet.</p>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Add from Core Matches, or search any property.</p>
                    </div>
                </template>

                <ol class="space-y-2 max-h-[55vh] overflow-y-auto pr-1" x-show="items.length > 0">
                    <template x-for="(item, idx) in items" :key="item.id">
                        <li class="flex items-center gap-2 rounded-md px-2 py-2"
                            style="background: var(--surface-2); border: 1px solid var(--border);"
                            draggable="true"
                            @dragstart="dragStart($event, idx)"
                            @dragover.prevent="dragOver($event, idx)"
                            @drop.prevent="drop($event, idx)"
                            @dragend="dragEnd()"
                            :style="dragIdx === idx ? 'background: var(--surface-2); border: 1px solid var(--brand-icon); opacity:0.6;' : 'background: var(--surface-2); border: 1px solid var(--border);'">
                            <span class="cursor-move select-none text-base" title="Drag to reorder" style="color: var(--text-muted);">⠿</span>
                            <span class="text-sm font-semibold w-5 text-right flex-shrink-0" style="color: var(--text-muted);" x-text="(idx + 1) + '.'"></span>
                            <span class="flex-1 text-sm truncate" :title="item.label" style="color: var(--text-primary);" x-text="item.label"></span>
                            <span class="text-xs flex-shrink-0" style="color: var(--text-muted);" x-text="item.docs + ' docs'"></span>
                            <form method="POST" :action="removeBase + '/' + item.id" class="flex-shrink-0"
                                  @submit.prevent="confirm('Remove this property from the pack?') && vpAction($el)">
                                @csrf
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Remove</button>
                            </form>
                        </li>
                    </template>
                </ol>

                <p class="mt-3 text-xs" style="color: var(--text-muted);">Drag rows to set the viewing order — this is the page order in both PDFs.</p>
            </div>

            {{-- Pack details --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
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

            {{-- Viewing appointment — reuse the SAME calendar prefill handoff as the
                 Schedule Viewing modal, fed with THIS pack's selected properties in
                 sort_order + the buyer as attendee + class=viewing. Drops the agent
                 into the pre-filled Calendar New Event screen (no parallel scheduler). --}}
            <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Viewing appointment</h3>
            @php
                $buyer = $pack->contact;
                $schedAttendees = $buyer ? [[
                    'id'    => $buyer->id,
                    'name'  => trim(($buyer->first_name ?? '') . ' ' . ($buyer->last_name ?? '')) ?: ('Contact #' . $buyer->id),
                    'type'  => 'contact',
                    'role'  => 'buyer_contact',
                    'phone' => $buyer->phone,
                    'email' => $buyer->email,
                ]] : [];
                // Pack's selected properties, in the agent's chosen drag order.
                $schedProps = $pack->viewingPackProperties
                    ->map(fn ($vpp) => ['id' => $vpp->property_id, 'address' => optional($vpp->property)->address ?: ''])
                    ->filter(fn ($p) => $p['id'] !== null)
                    ->values();
                $scheduleUrl = $buyer ? route('command-center.calendar', array_filter([
                    'view'               => 'day',
                    'prefill_class'      => 'viewing',
                    'prefill_contact_id' => $buyer->id,
                    'prefill_attendees'  => json_encode($schedAttendees),
                    'prefill_properties' => $schedProps->isNotEmpty() ? json_encode($schedProps->all()) : null,
                ], fn ($v) => $v !== null)) : null;
            @endphp
            @if($scheduleUrl && $pack->viewingPackProperties->isNotEmpty())
                <p class="text-xs mb-2" style="color: var(--text-muted);">Opens the Calendar with the buyer, this pack's {{ $pack->viewingPackProperties->count() }} {{ \Illuminate\Support\Str::plural('property', $pack->viewingPackProperties->count()) }} (in order), and a viewing pre-filled.</p>
                <a href="{{ $scheduleUrl }}" class="corex-btn-primary w-full no-underline" style="text-align:center;">Schedule Viewing</a>
            @else
                <p class="text-xs" style="color: var(--text-muted);">Add at least one property to schedule a viewing.</p>
            @endif

            <hr class="my-4" style="border-color: var(--border);">

            <form method="POST" action="{{ route('corex.viewing-packs.destroy', $pack) }}"
                  onsubmit="return confirm('Archive this viewing pack? You can recover it later.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="corex-btn-outline w-full" style="color: var(--ds-crimson); border-color: var(--ds-crimson);">Archive pack</button>
            </form>
            </div>{{-- /pack details card --}}
        </div>{{-- /sticky right column --}}
    </div>{{-- /grid + #vp-content (in-place swap region, AT-110 Bug 3) --}}
</div>

{{-- Redaction modal (Step 5b) — draw black boxes; output is a flattened image-only PDF --}}
<div x-data="redactionTool('{{ csrf_token() }}')"
     x-on:open-redactor.window="openRedactor($event.detail)"
     x-show="isOpen" x-cloak
     class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto"
     style="background: rgba(0,0,0,0.6); padding: 24px;">
    <div class="w-full max-w-4xl rounded-md" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="close()">
        <div class="flex items-center justify-between px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <div>
                <h3 class="text-base font-bold" style="color: var(--text-primary);">Redact document</h3>
                <p class="text-xs" style="color: var(--text-muted);" x-text="label"></p>
            </div>
            <button type="button" @click="close()" class="corex-btn-outline">Close</button>
        </div>

        <div class="px-5 py-4">
            <p class="text-xs mb-3" style="color: var(--text-muted);">
                Drag to draw a black box over anything that must not reach the buyer (e.g. an account number).
                On apply, every page is flattened to a raster image — the hidden text is destroyed, not covered.
            </p>

            <template x-if="loading">
                <p class="text-sm" style="color: var(--text-secondary);">Loading document…</p>
            </template>
            <template x-if="loadError">
                <p class="text-sm" style="color: var(--ds-crimson);" x-text="loadError"></p>
            </template>

            <div class="space-y-4" x-show="!loading && !loadError">
                <template x-for="page in pages" :key="page.index">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold" style="color: var(--text-muted);">Page <span x-text="page.index + 1"></span></span>
                            <button type="button" class="text-xs" style="color: var(--ds-crimson);" @click="clearPage(page.index)">Clear boxes</button>
                        </div>
                        <div class="relative inline-block select-none" style="max-width:100%;"
                             :data-page="page.index"
                             @mousedown.prevent="startDraw($event, page.index)"
                             @mousemove.prevent="moveDraw($event, page.index)"
                             @mouseup.prevent="endDraw($event, page.index)"
                             @mouseleave="endDraw($event, page.index)">
                            <img :src="page.data_uri" class="vp-redact-page-img block" :data-page="page.index"
                                 style="max-width:100%; height:auto; border:1px solid var(--border);" draggable="false">
                            {{-- drawn boxes (display coords) --}}
                            <template x-for="(box, bi) in (displayBoxes[page.index] || [])" :key="bi">
                                <div class="absolute" style="background: #000; opacity:0.85;"
                                     :style="`left:${box.x}px; top:${box.y}px; width:${box.w}px; height:${box.h}px;`"></div>
                            </template>
                            {{-- live drag rectangle --}}
                            <div class="absolute" x-show="drag.active && drag.page === page.index"
                                 style="background: rgba(0,0,0,0.5); border:1px dashed #fff;"
                                 :style="`left:${drag.x}px; top:${drag.y}px; width:${drag.w}px; height:${drag.h}px;`"></div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 px-5 py-3" style="border-top: 1px solid var(--border);">
            {{-- AT-110 Bug 2 — visible error surface: the tool NEVER fails silently. --}}
            <p class="text-xs flex-1" style="color: var(--ds-crimson);" x-show="applyError" x-cloak x-text="applyError"></p>
            <p class="text-xs flex-1" style="color: var(--text-muted);" x-show="!applyError && !loadError && boxCount() === 0" x-cloak>
                Draw at least one box, or apply to flatten the page (this still destroys the text layer).
            </p>
            <form method="POST" :action="postUrl" @submit.prevent="applyRedaction($event)" class="flex-shrink-0">
                @csrf
                {{-- boxes are injected as boxes[<page>][<i>][x|y|w|h] hidden inputs by prepareSubmit() --}}
                <div x-ref="boxesFields"></div>
                <button type="submit" class="corex-btn-primary" x-show="!loading && !loadError"
                        :disabled="applying" x-text="applying ? 'Applying…' : 'Apply redaction'"></button>
            </form>
        </div>
    </div>
</div>

<script>
function redactionTool(csrf) {
    return {
        isOpen: false,
        loading: false,
        loadError: '',
        applyError: '',
        applying: false,
        label: '',
        dataUrl: '',
        postUrl: '',
        pages: [],
        displayBoxes: {}, // pageIndex -> [{x,y,w,h} display px]
        drag: { active: false, page: null, startX: 0, startY: 0, x: 0, y: 0, w: 0, h: 0 },

        // AT-110 Bug 2 — renamed from open() so the handler can never resolve to the
        // global window.open(). Surfaces the REAL failure to the agent (status + server
        // message) instead of a single dead generic string.
        async openRedactor(detail) {
            this.dataUrl = detail.dataUrl;
            this.postUrl = detail.postUrl;
            this.label = detail.label || '';
            this.pages = [];
            this.displayBoxes = {};
            this.loadError = '';
            this.applyError = '';
            this.isOpen = true;
            this.loading = true;
            try {
                const res = await fetch(this.dataUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (!res.ok) {
                    let msg = '';
                    try { msg = (await res.json()).error || ''; } catch (_) {}
                    this.loadError = msg || ('This document could not be opened for redaction (HTTP ' + res.status + ').');
                    this.loading = false;
                    return;
                }
                const data = await res.json();
                this.pages = data.pages || [];
                if (!this.pages.length) { this.loadError = 'The document opened but produced no pages to redact.'; }
            } catch (e) {
                this.loadError = 'This document could not be opened for redaction: ' + (e && e.message ? e.message : 'network error') + '.';
            }
            this.loading = false;
        },
        close() { this.isOpen = false; },
        clearPage(p) { this.displayBoxes[p] = []; },
        boxCount() {
            return Object.values(this.displayBoxes).reduce((n, arr) => n + ((arr && arr.length) || 0), 0);
        },

        // AT-110 Bug 2/3 — apply via fetch (no full reload): burn boxes server-side, then
        // swap #vp-content so the doc's state badge flips to "Included ✓" in place. Any
        // failure is shown inline, never swallowed.
        async applyRedaction(e) {
            if (this.applying) return;
            this.applyError = '';
            this.applying = true;
            try {
                this.prepareSubmit();
                const form = e.target;
                const res = await fetch(this.postUrl, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    let msg = '';
                    try { msg = (await res.json()).error || ''; } catch (_) {}
                    this.applyError = msg || ('Redaction failed (HTTP ' + res.status + ').');
                    this.applying = false;
                    return;
                }
                this.applying = false;
                this.close();
                await vpSwapContent();
            } catch (err) {
                this.applyError = 'Redaction failed: ' + (err && err.message ? err.message : 'network error') + '.';
                this.applying = false;
            }
        },

        startDraw(e, page) {
            const r = e.currentTarget.getBoundingClientRect();
            this.drag = { active: true, page, startX: e.clientX - r.left, startY: e.clientY - r.top, x: e.clientX - r.left, y: e.clientY - r.top, w: 0, h: 0 };
        },
        moveDraw(e, page) {
            if (!this.drag.active || this.drag.page !== page) return;
            const r = e.currentTarget.getBoundingClientRect();
            const cx = e.clientX - r.left, cy = e.clientY - r.top;
            this.drag.x = Math.min(cx, this.drag.startX);
            this.drag.y = Math.min(cy, this.drag.startY);
            this.drag.w = Math.abs(cx - this.drag.startX);
            this.drag.h = Math.abs(cy - this.drag.startY);
        },
        endDraw(e, page) {
            if (!this.drag.active || this.drag.page !== page) return;
            if (this.drag.w > 3 && this.drag.h > 3) {
                if (!this.displayBoxes[page]) this.displayBoxes[page] = [];
                this.displayBoxes[page].push({ x: this.drag.x, y: this.drag.y, w: this.drag.w, h: this.drag.h });
            }
            this.drag = { active: false, page: null, startX: 0, startY: 0, x: 0, y: 0, w: 0, h: 0 };
        },

        // Convert display boxes → RASTER px (page.width / img.clientWidth) and
        // emit boxes[page][i][x|y|w|h] hidden inputs the controller validates.
        prepareSubmit(e) {
            const container = this.$refs.boxesFields;
            container.innerHTML = '';
            for (const page of this.pages) {
                const boxes = this.displayBoxes[page.index] || [];
                if (!boxes.length) continue;
                const img = document.querySelector('img.vp-redact-page-img[data-page="' + page.index + '"]');
                if (!img) continue;
                const scaleX = page.width / img.clientWidth;
                const scaleY = page.height / img.clientHeight;
                boxes.forEach((b, i) => {
                    const map = { x: b.x * scaleX, y: b.y * scaleY, w: b.w * scaleX, h: b.h * scaleY };
                    for (const k of ['x', 'y', 'w', 'h']) {
                        const inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = `boxes[${page.index}][${i}][${k}]`;
                        inp.value = Math.round(map[k]);
                        container.appendChild(inp);
                    }
                });
            }
            // form submits normally (CSRF + boxes[] fields)
        },
    };
}

// AT-109 — client-side search/filter/sort over the buyer's already-loaded
// canonical Core Match set. No server round-trips (small set); selection state
// (m.added) is server-rendered per row, so it survives filtering/sorting.
function coreMatchesUx(matches, scoreFloor) {
    return {
        matches: matches,
        scoreFloor: scoreFloor,
        q: '',
        selSuburbs: [],
        priceMin: '',
        priceMax: '',
        minScore: scoreFloor,
        sortBy: 'score',
        toggleSuburb(s) {
            const i = this.selSuburbs.indexOf(s);
            if (i === -1) this.selSuburbs.push(s); else this.selSuburbs.splice(i, 1);
        },
        reset() {
            this.q = ''; this.selSuburbs = []; this.priceMin = ''; this.priceMax = '';
            this.minScore = this.scoreFloor; this.sortBy = 'score';
        },
        filtered() {
            const q = this.q.trim().toLowerCase();
            const min = this.priceMin === '' ? null : Number(this.priceMin);
            const max = this.priceMax === '' ? null : Number(this.priceMax);
            const floor = Number(this.minScore) || 0;
            let out = this.matches.filter(m => {
                if (q && !((m.address + ' ' + m.suburb + ' ' + m.ref).toLowerCase().includes(q))) return false;
                if (this.selSuburbs.length && !this.selSuburbs.includes(m.suburb)) return false;
                if (min !== null && m.price < min) return false;
                if (max !== null && m.price > max) return false;
                if (m.score < floor) return false;
                return true;
            });
            const by = this.sortBy;
            out.sort((a, b) => {
                if (by === 'score') return b.score - a.score;
                if (by === 'price_asc') return a.price - b.price;
                if (by === 'price_desc') return b.price - a.price;
                if (by === 'suburb') return (a.suburb || '').localeCompare(b.suburb || '') || a.address.localeCompare(b.address);
                if (by === 'address') return a.address.localeCompare(b.address);
                return 0;
            });
            return out;
        },
    };
}

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

// AT-110 Bug 3 — in-place add/remove/redact. Re-render the server's truth into
// #vp-content (no full navigation), so scroll position is preserved and Alpine's
// MutationObserver re-initialises the swapped subtree automatically.
async function vpSwapContent() {
    const res = await fetch(window.location.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        credentials: 'same-origin',
    });
    const html = await res.text();
    const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('#vp-content');
    const cur = document.querySelector('#vp-content');
    if (fresh && cur) { cur.innerHTML = fresh.innerHTML; }
}

// Submit a form (add/remove property or document) without reloading the page.
// On any non-OK response or network failure, fall back to a normal full submit so
// the action ALWAYS happens — the in-place path is an enhancement, never a trap.
async function vpAction(form) {
    try {
        const res = await fetch(form.action, {
            method: 'POST',                       // _method spoofing carried in FormData for DELETE
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
            credentials: 'same-origin',
            redirect: 'follow',
        });
        if (!res.ok) { form.submit(); return; }
        await vpSwapContent();
    } catch (e) {
        form.submit();
    }
}
</script>
@endsection
