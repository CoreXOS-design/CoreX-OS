{{-- AT-231 P2b — Inbound attorney-correspondence REVIEW SCREEN (suspense queue).
     Reachable from Deals ("Comms Suspense") and Comms ("To File"). See
     .ai/specs/at231-inbound-attorney-comms-filing.md §3.7–3.9.
     Layout (2026-09-14, §3.9 "Split view"): frozen header, a 380px list on the left
     (To review / Recently filed tabs), the selected email read in full on the right
     with ONE pinned action bar under it. Every action is unchanged from the card
     layout it replaced: pick a deal, Confirm & file, Search all deals, Reject, Reassign. --}}
{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $firstPending = $items->first()?->id;
    $firstRecent  = $recent->first()?->id;
    $chipFor = function (?string $conf): array {
        return $conf === 'high'
            ? ['High confidence', 'color-mix(in srgb, var(--ds-green, #059669) 12%, transparent)', 'var(--ds-green, #059669)', 'none']
            : ($conf === 'medium'
                ? ['Medium', 'color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, transparent)', 'var(--ds-amber, #f59e0b)', 'none']
                : ['Needs a deal', 'var(--surface-2)', 'var(--text-muted)', '1px solid var(--border)']);
    };
    $controlStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    // Header search filters the visible list client-side on subject + sender + deal (the "hay").
    $dealLabel = fn ($d) => $d ? ($d->deal_no ? '#'.$d->deal_no : 'Deal '.$d->id) . ($d->property_address ? ' · '.$d->property_address : '') : '';
    $hayOf = fn ($s, $deal) => mb_strtolower(trim(($s->communication?->subject ?? '').' '.($s->communication?->from_display ?? '').' '.$dealLabel($deal)));
    $hays = ['review' => $items->map(fn ($s) => $hayOf($s, $s->suggestedDeal))->values()->all(), 'filed' => $recent->map(fn ($s) => $hayOf($s, $s->resolvedDeal))->values()->all()];
@endphp
<div class="w-full h-full flex flex-col gap-4" x-data="commsSuspense({{ (int) ($firstPending ?? 0) }}, {{ (int) ($firstRecent ?? 0) }}, @js($hays))">

    {{-- ── FROZEN HEADER ── --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <h1 class="text-base font-bold leading-tight whitespace-nowrap" style="color: var(--text-primary);">Comms Suspense</h1>
                <span class="text-xs px-2 py-0.5 rounded-full whitespace-nowrap" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ $items->total() }} to review</span>
                <p class="text-xs truncate" style="color: var(--text-muted);">Attorney email to file — confirm the deal once, the rest of that reference files itself.</p>
            </div>
            <label class="flex items-center gap-2 rounded-md px-3 py-1.5 w-full sm:w-64" style="{{ $controlStyle }}">
                <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted);"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" x-model="q" placeholder="Search subject, sender, deal…" class="w-full text-xs bg-transparent outline-none border-0 p-0" style="color: var(--text-primary);">
            </label>
        </div>
    </div>

    @if(session('status'))
        <div class="flex-shrink-0 rounded-md px-4 py-2.5 text-sm" style="background:color-mix(in srgb, var(--ds-green, #059669) 12%, transparent); color:var(--ds-green, #059669); border:1px solid color-mix(in srgb, var(--ds-green, #059669) 25%, transparent);">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="flex-shrink-0 rounded-md px-4 py-2.5 text-sm" style="background:color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color:var(--ds-crimson, #dc2626); border:1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 25%, transparent);">{{ session('error') }}</div>
    @endif

    {{-- hidden retargetable form for picker submits (verify to a chosen deal / reassign) --}}
    <form x-ref="pickForm" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="deal_id" x-ref="pickDealId">
    </form>

    {{-- ── SPLIT VIEW: list | reading pane ── --}}
    <div class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-[380px_minmax(0,1fr)] gap-4">

        {{-- LEFT — list with tabs --}}
        <div class="min-h-0 max-h-[40vh] lg:max-h-none flex flex-col rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center flex-shrink-0 px-2" style="border-bottom: 1px solid var(--border);">
                <button type="button" @click="tab = 'review'"
                        class="flex items-center gap-2 px-3 py-2 text-xs font-medium border-b-2 -mb-px"
                        :style="tab === 'review' ? 'color: var(--text-primary); border-color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-muted); border-color: transparent;'">
                    To review
                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background: var(--brand-icon, #0ea5e9); color: #fff;">{{ $items->total() }}</span>
                </button>
                <button type="button" @click="tab = 'filed'"
                        class="flex items-center gap-2 px-3 py-2 text-xs font-medium border-b-2 -mb-px"
                        :style="tab === 'filed' ? 'color: var(--text-primary); border-color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-muted); border-color: transparent;'">
                    Recently filed
                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">{{ $recent->count() }}</span>
                </button>
            </div>

            {{-- pending list --}}
            <div x-show="tab === 'review'" class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll">
                @forelse($items as $s)
                    @php
                        $c    = $s->communication;
                        $chip = $chipFor($s->confidence);
                        $sug  = $s->suggestedDeal;
                        $sugLabel = $dealLabel($sug);
                        $hay  = $hayOf($s, $sug);
                    @endphp
                    <button type="button" @click="sel.review = {{ (int) $s->id }}" x-show="hit($el.dataset.hay)" data-hay="{{ $hay }}"
                            class="w-full text-left flex flex-col gap-0.5 px-3.5 py-2.5 transition-colors"
                            :style="sel.review === {{ (int) $s->id }} ? 'background: var(--surface-2); box-shadow: inset 3px 0 0 var(--brand-icon, #0ea5e9); border-bottom: 1px solid var(--border);' : 'border-bottom: 1px solid var(--border);'">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background:{{ $chip[1] }}; color:{{ $chip[2] }}; border:{{ $chip[3] }};">{{ $chip[0] }}</span>
                            <span class="text-[11px] whitespace-nowrap" style="color: var(--text-muted);">{{ optional($c?->occurred_at)->diffForHumans() }}</span>
                        </div>
                        <div class="text-[13px] truncate" :class="sel.review === {{ (int) $s->id }} ? 'font-semibold' : 'font-medium'" style="color: var(--text-primary);">{{ $c?->subject ?: '(no subject)' }}</div>
                        <div class="text-xs truncate" style="color: var(--text-secondary);">{{ $c?->from_display ?? '—' }}</div>
                        <div class="text-[11px] truncate" style="color: var(--text-muted);">
                            {{ $sug ? 'Suggested '.$sugLabel : 'No suggestion yet' }}@if($c && $c->attachments->isNotEmpty()) · {{ $c->attachments->count() }} {{ \Illuminate\Support\Str::plural('attachment', $c->attachments->count()) }}@endif
                        </div>
                    </button>
                @empty
                    <div class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">Nothing to review — every attorney email has filed itself.</div>
                @endforelse
                <div class="px-3.5 py-2 text-xs" x-show="q && !hays.review.some(h => hit(h))" x-cloak style="color: var(--text-muted);">No emails match your search.</div>
            </div>
            @if($items->hasPages())
                <div x-show="tab === 'review'" class="flex-shrink-0 px-3 py-2 text-xs" style="border-top: 1px solid var(--border);">{{ $items->links() }}</div>
            @endif

            {{-- recently filed list --}}
            <div x-show="tab === 'filed'" x-cloak class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll">
                @forelse($recent as $s)
                    @php
                        $c   = $s->communication;
                        $dl  = $s->resolvedDeal?->deal_no ? '#'.$s->resolvedDeal->deal_no : 'Deal '.$s->resolved_deal_id;
                        $hay = $hayOf($s, $s->resolvedDeal);
                    @endphp
                    <button type="button" @click="sel.filed = {{ (int) $s->id }}" x-show="hit($el.dataset.hay)" data-hay="{{ $hay }}"
                            class="w-full text-left flex flex-col gap-0.5 px-3.5 py-2.5 transition-colors"
                            :style="sel.filed === {{ (int) $s->id }} ? 'background: var(--surface-2); box-shadow: inset 3px 0 0 var(--brand-icon, #0ea5e9); border-bottom: 1px solid var(--border);' : 'border-bottom: 1px solid var(--border);'">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[11px] font-semibold" style="color: var(--text-secondary);">→ {{ $dl }}</span>
                            <span class="text-[11px] whitespace-nowrap" style="color: var(--text-muted);">{{ optional($s->resolved_at)->diffForHumans() }}</span>
                        </div>
                        <div class="text-[13px] font-medium truncate" style="color: var(--text-primary);">{{ $c?->subject ?: '(no subject)' }}</div>
                        <div class="text-xs truncate" style="color: var(--text-secondary);">{{ $c?->from_display ?? '—' }}</div>
                    </button>
                @empty
                    <div class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">Nothing filed recently.</div>
                @endforelse
                <div class="px-3.5 py-2 text-xs" x-show="q && !hays.filed.some(h => hit(h))" x-cloak style="color: var(--text-muted);">No filed emails match your search.</div>
            </div>
        </div>

        {{-- RIGHT — reading pane + pinned action bar --}}
        <div class="min-h-0 flex flex-col rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">

            {{-- pending panes --}}
            @foreach($items as $s)
                @php
                    $c    = $s->communication;
                    $chip = $chipFor($s->confidence);
                    $sugId  = (int) ($s->suggested_deal_id ?? 0);
                    $inList = $agentDeals->firstWhere('id', $sugId);
                @endphp
                <div x-show="tab === 'review' && sel.review === {{ (int) $s->id }}" x-cloak class="flex-1 min-h-0 flex flex-col">
                    <div class="flex-shrink-0 flex flex-col gap-1.5 px-5 py-4" style="border-bottom: 1px solid var(--border);">
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background:{{ $chip[1] }}; color:{{ $chip[2] }}; border:{{ $chip[3] }};">{{ $chip[0] }}</span>
                            <span class="text-[11px]" style="color: var(--text-muted);">Received {{ optional($c?->occurred_at)->format('D j M Y, H:i') ?? '—' }}@if($c?->occurred_at) · {{ $c->occurred_at->diffForHumans() }}@endif</span>
                        </div>
                        <div class="text-[15px] font-semibold leading-snug" style="color: var(--text-primary);">{{ $c?->subject ?: '(no subject)' }}</div>
                        <div class="text-xs" style="color: var(--text-secondary);">From <span class="font-medium" style="color: var(--text-primary);">{{ $c?->from_display ?? '—' }}</span></div>
                        @if($c && $c->attachments->isNotEmpty())
                            <div class="flex items-center gap-2 mt-1 flex-wrap">
                                @foreach($c->attachments as $att)
                                    <a href="{{ route('corex.comms-suspense.attachment', $att) }}" target="_blank"
                                       class="inline-flex items-center gap-1.5 text-[11px] px-2 py-1 rounded no-underline" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                        {{ \Illuminate\Support\Str::limit($att->filename ?: 'attachment', 40) }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll px-5 py-4 text-[13px] leading-relaxed whitespace-pre-line" style="background: var(--surface-2); color: var(--text-secondary);">@if($c?->display_body){{ $c->display_body }}@else<span style="color: var(--text-muted);">(no message body)</span>@endif</div>
                    <div class="flex-shrink-0 flex items-center gap-2.5 px-5 py-3 flex-wrap" style="border-top: 1px solid var(--border);">
                        <form method="POST" action="{{ route('corex.comms-suspense.verify', $s) }}" class="flex items-center gap-2.5 flex-1 min-w-0 flex-wrap">
                            @csrf
                            <span class="text-xs whitespace-nowrap" style="color: var(--text-muted);">File to</span>
                            <select name="deal_id" class="flex-1 min-w-[220px] text-xs px-2.5 py-2 rounded-md" style="{{ $controlStyle }}">
                                <option value="">— pick a deal —</option>
                                @if($sugId && ! $inList && $s->suggestedDeal)
                                    <option value="{{ $sugId }}" selected>{{ $s->suggestedDeal->deal_no ? '#'.$s->suggestedDeal->deal_no : 'Deal '.$sugId }}{{ $s->suggestedDeal->property_address ? ' · '.\Illuminate\Support\Str::limit($s->suggestedDeal->property_address, 40) : '' }} — Suggested</option>
                                @endif
                                @foreach($agentDeals as $d)
                                    <option value="{{ $d['id'] }}" {{ $d['id'] === $sugId ? 'selected' : '' }}>{{ $d['label'] }}{{ $d['id'] === $sugId ? ' — Suggested' : '' }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-md whitespace-nowrap" style="background: var(--brand-button, #0ea5e9); color: #fff;">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                Confirm &amp; file
                            </button>
                        </form>
                        <button type="button" @click="open('{{ route('corex.comms-suspense.verify', $s) }}')"
                                class="inline-flex items-center gap-1.5 text-xs px-3 py-2 rounded-md whitespace-nowrap" style="{{ $controlStyle }}">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            Search all deals
                        </button>
                        <form method="POST" action="{{ route('corex.comms-suspense.dismiss', $s) }}"
                              onsubmit="return confirm('Reject this email? It will not be filed to any deal.');">
                            @csrf
                            <button type="submit" class="text-xs px-3 py-2 rounded-md whitespace-nowrap" style="background: transparent; color: var(--ds-crimson, #c41e3a); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);">Reject</button>
                        </form>
                    </div>
                </div>
            @endforeach

            {{-- recently filed panes --}}
            @foreach($recent as $s)
                @php
                    $c  = $s->communication;
                    $dl = $s->resolvedDeal?->deal_no ? '#'.$s->resolvedDeal->deal_no : 'Deal '.$s->resolved_deal_id;
                @endphp
                <div x-show="tab === 'filed' && sel.filed === {{ (int) $s->id }}" x-cloak class="flex-1 min-h-0 flex flex-col">
                    <div class="flex-shrink-0 flex flex-col gap-1.5 px-5 py-4" style="border-bottom: 1px solid var(--border);">
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--ds-green, #059669) 12%, transparent); color:var(--ds-green, #059669);">Filed to {{ $dl }}</span>
                            <span class="text-[11px]" style="color: var(--text-muted);">{{ $s->resolved_at ? 'Filed '.$s->resolved_at->diffForHumans() : '' }}@if($c?->occurred_at) · received {{ $c->occurred_at->format('D j M Y, H:i') }}@endif</span>
                        </div>
                        <div class="text-[15px] font-semibold leading-snug" style="color: var(--text-primary);">{{ $c?->subject ?: '(no subject)' }}</div>
                        <div class="text-xs" style="color: var(--text-secondary);">From <span class="font-medium" style="color: var(--text-primary);">{{ $c?->from_display ?? '—' }}</span>@if($s->resolvedDeal?->property_address) · filed to <span style="color: var(--text-primary);">{{ $s->resolvedDeal->property_address }}</span>@endif</div>
                    </div>
                    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll px-5 py-4 text-[13px] leading-relaxed whitespace-pre-line" style="background: var(--surface-2); color: var(--text-secondary);">@if($c?->display_body){{ $c->display_body }}@else<span style="color: var(--text-muted);">(no message body)</span>@endif</div>
                    <div class="flex-shrink-0 flex items-center gap-2.5 px-5 py-3 flex-wrap" style="border-top: 1px solid var(--border);">
                        <span class="text-xs flex-1" style="color: var(--text-muted);">Went to the wrong deal? Reassign moves it and corrects the learned reference.</span>
                        <button type="button" @click="open('{{ route('corex.comms-suspense.reassign', $s) }}')"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-md whitespace-nowrap" style="{{ $controlStyle }}">Reassign…</button>
                    </div>
                </div>
            @endforeach

            {{-- nothing selected / nothing to show --}}
            <div x-show="(tab === 'review' && !sel.review) || (tab === 'filed' && !sel.filed)" x-cloak class="flex-1 flex items-center justify-center px-6 text-center text-sm" style="color: var(--text-muted);">
                <span x-show="tab === 'review'">{{ $items->total() > 0 ? 'Pick an email on the left to read it.' : 'Nothing to review — every attorney email has filed itself.' }}</span>
                <span x-show="tab === 'filed'">{{ $recent->isNotEmpty() ? 'Pick a filed email on the left.' : 'Nothing filed recently.' }}</span>
            </div>
        </div>
    </div>

    {{-- ── DEAL PICKER (link / reassign) ── --}}
    <div x-show="picker" x-cloak @keydown.escape.window="picker=false"
         class="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4" style="background:rgba(0,0,0,0.4);">
        <div class="w-full max-w-lg rounded-md p-4" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb);" @click.outside="picker=false">
            <div class="flex items-center justify-between mb-2">
                <div class="text-sm font-semibold" style="color:var(--text-primary, #111827);">Pick the deal to file this correspondence to</div>
                <button type="button" @click="picker=false" style="color:var(--text-muted, #6b7280);">✕</button>
            </div>
            <input type="text" x-model="pq" @input.debounce.300ms="search()" placeholder="Search by address, deal number, or seller…"
                   class="w-full text-sm px-3 py-2 rounded mb-2" style="background:var(--surface-2, #f3f4f6); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);">
            <div class="max-h-64 overflow-y-auto space-y-1 corex-brand-scroll">
                <template x-if="loading"><div class="text-xs px-2 py-2" style="color:var(--text-muted,#6b7280);">Searching…</div></template>
                <template x-for="r in results" :key="r.id">
                    <button type="button" @click="pick(r.id)" class="w-full text-left text-sm px-3 py-2 rounded" style="background:var(--surface-2, #f3f4f6); color:var(--text-primary, #111827);" x-text="r.label"></button>
                </template>
                <template x-if="!loading && pq.length>=2 && results.length===0"><div class="text-xs px-2 py-2" style="color:var(--text-muted,#6b7280);">No matching deals.</div></template>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function commsSuspense(firstPending, firstRecent, hays) {
    return {
        tab: 'review',
        sel: { review: firstPending || null, filed: firstRecent || null },
        q: '',
        hays: hays || { review: [], filed: [] },
        picker: false, actionUrl: '', pq: '', results: [], loading: false,
        hit(hay) {
            const needle = (this.q || '').trim().toLowerCase();
            return !needle || (hay || '').includes(needle);
        },
        open(url) { this.actionUrl = url; this.pq = ''; this.results = []; this.picker = true; },
        async search() {
            if (this.pq.length < 2) { this.results = []; return; }
            this.loading = true;
            try {
                const res = await fetch('{{ route('corex.comms-suspense.deal-search') }}?q=' + encodeURIComponent(this.pq), { headers: { 'Accept': 'application/json' } });
                this.results = res.ok ? await res.json() : [];
            } catch (e) { this.results = []; }
            this.loading = false;
        },
        pick(id) {
            this.$refs.pickForm.action = this.actionUrl;
            this.$refs.pickDealId.value = id;
            this.picker = false;
            this.$refs.pickForm.submit();
        },
    };
}
</script>
@endpush
@endsection
