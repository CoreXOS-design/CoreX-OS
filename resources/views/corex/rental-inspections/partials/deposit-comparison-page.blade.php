@extends('layouts.corex')

{{--
    .ai/specs/rental-inspections.md §45.7a (Build I-7) — the MOVE-OUT COMPARISON. One screen, both photo sets, differences
    marked, classified by the agent. (This was the "deposit comparison" page: text and a photo COUNT, with a hard-coded
    "good" test and a baseline that could disagree with the property tab.)

      - Baseline = the tenancy's completed move-in, found along the lease chain; interim inspections and fault reports are
        context on the row, never the baseline.
      - Every condition is shown as the agency's own LABEL, coloured by its own severity bucket.
      - Differences are worked out when the page loads (never stored): Worse / Different / Same (already present) / Better /
        New item / Not at move-out.
      - Photos open in a paired viewer (move-in beside move-out, linked photos first), with their capture times.
      - The agent classifies a marked row (append-only, a note is required). Nothing on this page is money — a "charge to
        tenant" classification is the agent's recorded judgement and the future source of deduction lines (parked finance build).
--}}

@php
    $severityColor = fn ($s) => \App\Models\RentalInspectionSetting::SEVERITY_COLORS[$s] ?? '#6b7280';
    $differenceStyle = fn ($d) => match ($d) {
        'worse' => 'background: color-mix(in srgb, #c41e3a 14%, transparent); color:#c41e3a;',
        'different', 'new_item', 'not_at_move_out' => 'background: color-mix(in srgb, #f59e0b 16%, transparent); color:#b45309;',
        'better' => 'background: color-mix(in srgb, #16a34a 14%, transparent); color:#15803d;',
        default => 'background: var(--surface-2); color: var(--text-muted);',
    };
    $queryWith = fn (array $over) => array_filter(array_merge(request()->only(['q', 'room', 'difference', 'classification', 'differences_only', 'sort']), $over), fn ($v) => $v !== null && $v !== '' && $v !== false);
@endphp

@section('content')
<div class="p-6 max-w-6xl mx-auto space-y-4"
     x-data="{
        viewer: {{ Js::from($comparison['viewer']) }},
        vOpen: false, vKey: null, vIdx: 0,
        openViewer(key, idx) { if (!this.viewer[key]) return; this.vKey = key; this.vIdx = idx; this.vOpen = true; },
        closeViewer() { this.vOpen = false; },
        pairs() { return this.vKey ? this.viewer[this.vKey].pairs : []; },
        cur() { return this.pairs()[this.vIdx] || { in: null, out: null }; },
        next() { if (this.vIdx < this.pairs().length - 1) this.vIdx++; },
        prev() { if (this.vIdx > 0) this.vIdx--; },
     }"
     @keydown.window.escape="closeViewer()" @keydown.window.arrow-right="vOpen && next()" @keydown.window.arrow-left="vOpen && prev()">

    @if(session('success'))
        <div class="text-xs p-2 rounded" data-qa="mo-flash" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">{{ $errors->first() }}</div>
    @endif

    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-lg font-semibold">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <span class="text-xs" style="color: var(--text-muted);">Move-out comparison — {{ $inspection->lease?->tenantNames() ?? 'Unknown tenant' }}</span>
        </div>
        <div class="flex items-center gap-2">
            @if($inventory)
                <a href="{{ route('corex.rental-inventories.comparison', $inventory) }}" class="corex-btn-outline text-xs" data-qa="mo-inventory-link">Inventory comparison</a>
            @endif
            <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs">&larr; Out-inspection</a>
        </div>
    </div>

    @unless($inInspection)
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
            No move-in inspection was found for this tenancy — nothing to compare against yet. Every item below is shown as "New item".
        </div>
    @else
        <p class="text-xs" style="color: var(--text-muted);" data-qa="mo-baseline">
            Compared with the move-in inspection of {{ ($inInspection->completed_at ?? $inInspection->scheduled_for ?? $inInspection->created_at)?->format('j M Y') }}
            @unless($comparison['baseline_completed']) <strong>(not completed — compared with whatever was recorded)</strong> @endunless
            @if($inInspection->lease_id !== $inspection->lease_id) <span>— from an earlier lease term of this tenancy</span> @endif.
            Interim inspections and fault reports appear on the rows they touched; they are never the comparison.
        </p>
    @endunless

    {{-- counts: each chip filters to that difference --}}
    <div class="flex flex-wrap items-center gap-2" data-qa="mo-counts">
        @foreach($differenceLabels as $key => $label)
            <a href="{{ route('corex.rental-inspections.deposit-comparison', [$inspection] + $queryWith(['difference' => ($filters['difference'] ?? null) === $key ? null : $key])) }}"
               class="px-2.5 py-1 rounded-full text-xs font-semibold" style="{{ $differenceStyle($key) }} {{ ($filters['difference'] ?? null) === $key ? 'outline: 2px solid var(--brand-icon, #0ea5e9);' : '' }}">
                {{ $label }}: {{ $comparison['counts'][$key] ?? 0 }}
            </a>
        @endforeach
    </div>

    @if(!empty($comparison['header_facts']))
    <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Keys, remotes &amp; meters</h2>
        @foreach($comparison['header_facts'] as $fact)
            <div class="flex items-center justify-between text-sm" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span>{{ $fact['label'] }}</span>
                <span style="color: var(--text-muted);">
                    {{ $fact['in'] ?? '—' }} <span style="opacity:.6;">at move-in</span> &rarr; {{ $fact['out'] ?? '—' }} <span style="opacity:.6;">at move-out</span>
                    @if($fact['changed']) <span class="ds-badge ds-badge-warning">Changed</span> @endif
                </span>
            </div>
        @endforeach
    </div>
    @endif

    {{-- search / filter / sort --}}
    <form method="GET" action="{{ route('corex.rental-inspections.deposit-comparison', $inspection) }}" class="flex flex-wrap items-end gap-3" data-qa="mo-filters">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Item, room or note" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Room</label><br>
            <select name="room" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All rooms</option>
                @foreach($roomOptions as $opt)
                    <option value="{{ $opt['id'] }}" @selected((string) ($filters['room'] ?? '') === (string) $opt['id'])>{{ $opt['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Difference</label><br>
            <select name="difference" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($differenceLabels as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['difference'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Classification</label><br>
            <select name="classification" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                <option value="none" @selected(($filters['classification'] ?? '') === 'none')>Not classified yet</option>
                <option value="any" @selected(($filters['classification'] ?? '') === 'any')>Classified</option>
                @foreach($dispositionLabels as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['classification'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Order</label><br>
            <select name="sort" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="walking" @selected(($filters['sort'] ?? 'walking') === 'walking')>Walking order</option>
                <option value="severity" @selected(($filters['sort'] ?? '') === 'severity')>Worst first</option>
            </select>
        </div>
        <label class="flex items-center gap-1.5 text-xs pb-2" style="color: var(--text-secondary);">
            <input type="checkbox" name="differences_only" value="1" @checked($filters['differences_only'])> Differences only
        </label>
        <button type="submit" class="corex-btn-outline text-xs">Apply</button>
        @if(request()->hasAny(['q', 'room', 'difference', 'classification', 'differences_only', 'sort']))
            <a href="{{ route('corex.rental-inspections.deposit-comparison', $inspection) }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    @forelse($comparison['rooms'] as $room)
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ open: true }" data-qa="mo-room-{{ $room['key'] }}">
        <button type="button" @click="open = !open" class="w-full flex items-center justify-between px-4 py-2.5 text-left">
            <span class="text-sm font-semibold">{{ $room['label'] }}
                <span class="text-xs font-normal" style="color: var(--text-muted);">· {{ count($room['rows']) }} item{{ count($room['rows']) === 1 ? '' : 's' }}@if($room['marked']) · <strong style="color:#b45309;">{{ $room['marked'] }} to look at</strong>@endif</span>
            </span>
            <span class="text-xs" style="color: var(--text-muted);" x-text="open ? 'Hide' : 'Show'"></span>
        </button>

        <div x-show="open" class="px-4 pb-4 space-y-3">
            @if($room['room_pairs'])
                <div class="rounded-md p-3" style="background: var(--surface-2);" data-qa="mo-room-photos-{{ $room['key'] }}">
                    <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">General room photos</div>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach(['in' => 'Move-in', 'out' => 'Move-out'] as $side => $sideLabel)
                        <div>
                            <div class="text-[11px] font-semibold mb-1" style="color: var(--text-muted);">{{ $sideLabel }}</div>
                            <div class="flex flex-wrap gap-2">
                                @foreach($room['room_pairs'] as $i => $pair)
                                    @if($pair[$side])
                                    <button type="button" @click="openViewer('{{ $room['key'] }}', {{ $i }})" class="text-left" title="{{ $pair[$side]['caption'] }}">
                                        <img src="{{ $pair[$side]['url'] }}" alt="" class="rounded" style="width:84px; height:64px; object-fit:cover; border:1px solid var(--border);">
                                        <span class="block text-[10px]" style="color: var(--text-muted); max-width:84px;">{{ $pair[$side]['short'] }}</span>
                                    </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @forelse($room['rows'] as $row)
            @php $item = $row['item']; $finding = $row['finding']; @endphp
            <div class="text-sm space-y-2 pt-2" style="border-top: 1px solid var(--border);" data-qa="mo-row-{{ $row['key'] }}" data-difference="{{ $row['difference'] }}">
                <div class="flex items-center justify-between gap-3">
                    <span class="font-medium">{{ $item->label }}@if($item->is_retired) <span class="text-xs" style="color: var(--text-muted);">(retired item)</span>@endif</span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold" style="{{ $differenceStyle($row['difference']) }}">{{ $row['difference_label'] }}</span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    @foreach(['in' => 'Move-in', 'out' => 'Move-out'] as $side => $sideLabel)
                    @php $cell = $row[$side]; @endphp
                    <div class="rounded-md p-2.5 space-y-1.5" style="background: var(--surface-2);">
                        <div class="text-[11px] font-semibold" style="color: var(--text-muted);">{{ $sideLabel }}</div>
                        @if($cell)
                            <div><span class="inline-block px-2 py-0.5 rounded text-xs font-semibold text-white" style="background: {{ $severityColor($cell['severity']) }};">{{ $cell['label'] }}</span></div>
                            @if($cell['note'])<div class="text-xs" style="color: var(--text-secondary);">{{ $cell['note'] }}</div>@endif
                        @else
                            <div class="text-xs" style="color: var(--text-muted);">Not recorded</div>
                        @endif
                        @if($row['pairs'])
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($row['pairs'] as $i => $pair)
                                    @if($pair[$side])
                                    <button type="button" @click="openViewer('{{ $row['key'] }}', {{ $i }})" class="text-left" title="{{ $pair[$side]['caption'] }}" data-qa="mo-photo-{{ $row['key'] }}-{{ $side }}-{{ $i }}">
                                        <img src="{{ $pair[$side]['url'] }}" alt="" class="rounded" style="width:72px; height:56px; object-fit:cover; border:1px solid var(--border);">
                                        <span class="block text-[10px]" style="color: var(--text-muted); max-width:72px;">{{ $pair[$side]['short'] }}</span>
                                    </button>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @endforeach
                </div>

                @if($row['marked'] && $row['context'])
                    <div class="rounded-md p-2.5 text-xs space-y-1" style="border: 1px dashed var(--border);" data-qa="mo-context-{{ $row['key'] }}">
                        <div class="font-semibold" style="color: var(--text-secondary);">What happened in between</div>
                        @foreach($row['context'] as $entry)
                            <div style="color: var(--text-secondary);">
                                <span style="color: var(--text-muted);">{{ $entry['when']?->format('j M Y') ?? '—' }}</span> ·
                                @if($entry['url']) <a href="{{ $entry['url'] }}" style="color: var(--brand-icon, #2563eb);">{{ $entry['text'] }}</a> @else {{ $entry['text'] }} @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($row['marked'])
                    @if($finding)
                        <div class="text-xs rounded px-2 py-1" style="background: var(--surface-2);" data-qa="mo-finding-{{ $row['key'] }}">
                            Classified <strong>{{ $dispositionLabels[$finding->disposition] ?? $finding->disposition }}</strong>
                            by {{ $finding->recordedBy?->name }} — {{ $finding->note }}
                        </div>
                    @endif
                    @permission('rental_inspections.review_deposit_comparison')
                        <form method="POST" action="{{ route('corex.rental-inspections.deposit-comparison.finding', [$inspection, $item->id]) }}" class="flex flex-wrap items-center gap-2" data-qa="mo-classify-{{ $row['key'] }}">
                            @csrf
                            <select name="disposition" class="text-xs rounded px-2 py-1" style="border: 1px solid var(--border);">
                                @foreach($dispositionLabels as $key => $label)
                                    <option value="{{ $key }}" @selected(($finding->disposition ?? null) === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="note" required maxlength="2000" placeholder="Reasoning (required)" class="text-xs rounded px-2 py-1 flex-1" style="border: 1px solid var(--border); min-width: 12rem;">
                            <button type="submit" class="corex-btn-outline text-xs">{{ $finding ? 'Update' : 'Record' }}</button>
                        </form>
                    @endpermission
                @endif
            </div>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No items in this room match.</p>
            @endforelse
        </div>
    </div>
    @empty
    <div class="rounded-md p-6 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);" data-qa="mo-empty">
        @if($comparison['total_rows'] === 0)
            Nothing has been recorded on both inspections yet — there is nothing to compare.
        @else
            No items match this search or filter. Try clearing a filter.
        @endif
    </div>
    @endforelse

    <p class="text-xs" style="color: var(--text-muted);">
        This is a record of what was found, for review — no amount has been calculated or applied against any deposit.
    </p>

    {{-- Paired photo viewer: move-in beside move-out, linked photos first. Left/right arrows or the buttons step through
         the photos of the row that was opened; Esc closes. --}}
    <div x-show="vOpen" x-cloak class="fixed inset-0 z-50 flex flex-col" style="background: rgba(8,10,14,.94);" @click.self="closeViewer()" data-qa="mo-viewer">
        <div class="flex items-center justify-between px-4 py-3 text-white">
            <div class="text-sm font-semibold" x-text="vKey ? viewer[vKey].label : ''"></div>
            <div class="flex items-center gap-3">
                <span class="text-xs" x-text="(vIdx + 1) + ' of ' + pairs().length"></span>
                <button type="button" @click="prev()" :disabled="vIdx === 0" class="px-3 py-1 rounded text-xs font-semibold" style="background:#1e262f;">&larr; Previous</button>
                <button type="button" @click="next()" :disabled="vIdx >= pairs().length - 1" class="px-3 py-1 rounded text-xs font-semibold" style="background:#1e262f;">Next &rarr;</button>
                <button type="button" @click="closeViewer()" aria-label="Close" class="px-3 py-1 rounded text-lg" style="background:#1e262f;" data-qa="mo-viewer-close">&times;</button>
            </div>
        </div>
        <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3 px-4 pb-4 min-h-0">
            <template x-for="side in ['in', 'out']" :key="side">
                <div class="flex flex-col min-h-0">
                    <div class="text-xs font-semibold text-white mb-1" x-text="side === 'in' ? 'Move-in' : 'Move-out'"></div>
                    <div class="flex-1 min-h-0 flex items-center justify-center rounded" style="background:#000;">
                        <template x-if="cur()[side]">
                            <img :src="cur()[side].url" alt="" style="max-width:100%; max-height:62vh; object-fit:contain;" :data-qa="'mo-viewer-img-' + side">
                        </template>
                        <template x-if="!cur()[side]">
                            <span class="text-xs" style="color: rgba(255,255,255,.6);">No photo on this side</span>
                        </template>
                    </div>
                    <div class="text-xs mt-1" style="color: rgba(255,255,255,.85);" x-show="cur()[side]">
                        <span x-text="cur()[side] ? cur()[side].caption : ''"></span>
                        <span x-show="cur()[side] && cur()[side].note"> — <span x-text="cur()[side] ? cur()[side].note : ''"></span></span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection
