{{-- AT-231 P2b — Inbound attorney-correspondence REVIEW SCREEN (suspense queue).
     Reachable from Deals ("Comms Suspense") and Comms ("To File"). See
     .ai/specs/at231-inbound-attorney-comms-filing.md §3.7–3.8. --}}
{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data="commsSuspense()">

    {{-- header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a); color:#fff;">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Comms Suspense — attorney email to file</h1>
                <p class="text-xs opacity-80 mt-1">Returns from attorneys that CoreX could not auto-file. Confirm the deal, link to another, or reject. Once you confirm the first email of a correspondence, the same reference files automatically after that.</p>
            </div>
            <span class="text-xs px-2 py-1 rounded-full" style="background:rgba(255,255,255,0.15);">{{ $items->total() }} to review</span>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:rgba(16,185,129,0.12); color:#10b981; border:1px solid rgba(16,185,129,0.25);">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color:#ef4444; border:1px solid rgba(239,68,68,0.25);">{{ session('error') }}</div>
    @endif

    {{-- hidden retargetable form for picker submits (verify to a chosen deal / reassign) --}}
    <form x-ref="pickForm" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="deal_id" x-ref="pickDealId">
    </form>

    {{-- ── PENDING QUEUE ── --}}
    <div class="space-y-3">
        @forelse($items as $s)
            @php $c = $s->communication; @endphp
            <div class="rounded-md px-5 py-4" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb);">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            @php
                                $conf = $s->confidence;
                                $chip = $conf === 'high' ? ['High confidence','rgba(16,185,129,0.12)','#10b981']
                                      : ($conf === 'medium' ? ['Medium','rgba(245,158,11,0.14)','#b45309']
                                      : ['Needs a deal','rgba(107,114,128,0.14)','#6b7280']);
                            @endphp
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background:{{ $chip[1] }}; color:{{ $chip[2] }};">{{ $chip[0] }}</span>
                            <span class="text-xs" style="color:var(--text-muted, #6b7280);">{{ optional($c?->occurred_at)->diffForHumans() }}</span>
                        </div>
                        <div class="mt-1 text-sm font-medium truncate" style="color:var(--text-primary, #111827);">{{ $c?->subject ?: '(no subject)' }}</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary, #374151);">From: {{ $c?->from_display ?? '—' }}</div>
                        @if($c?->display_body)
                            <div class="text-xs mt-1 line-clamp-2" style="color:var(--text-muted, #6b7280);">{{ \Illuminate\Support\Str::limit($c->display_body, 200) }}</div>
                        @endif
                        @if($c && $c->attachments->isNotEmpty())
                            <div class="flex items-center gap-2 mt-2 flex-wrap">
                                @foreach($c->attachments as $att)
                                    <a href="{{ route('corex.comms-suspense.attachment', $att) }}" target="_blank"
                                       class="text-[11px] px-2 py-1 rounded no-underline" style="background:var(--surface-2, #f3f4f6); color:var(--text-secondary, #374151);">📎 {{ \Illuminate\Support\Str::limit($att->filename ?: 'attachment', 32) }}</a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- actions --}}
                    <div class="flex flex-col items-stretch gap-2 shrink-0" style="min-width:210px;">
                        @if($s->suggested_deal_id && $s->suggestedDeal)
                            <div class="text-[11px] text-right" style="color:var(--text-muted, #6b7280);">Suggested:
                                <span style="color:var(--text-primary, #111827);">{{ $s->suggestedDeal->deal_no ? '#'.$s->suggestedDeal->deal_no : 'Deal '.$s->suggestedDeal->id }}</span>
                                @if($s->suggestedDeal->property_address) · {{ \Illuminate\Support\Str::limit($s->suggestedDeal->property_address, 26) }} @endif
                            </div>
                            <form method="POST" action="{{ route('corex.comms-suspense.verify', $s) }}">
                                @csrf
                                <input type="hidden" name="deal_id" value="{{ $s->suggested_deal_id }}">
                                <button type="submit" class="w-full text-xs font-semibold px-3 py-2 rounded" style="background:var(--brand-default, #0b2a4a); color:#fff;">Confirm &amp; file</button>
                            </form>
                        @endif
                        <button type="button" @click="open('{{ route('corex.comms-suspense.verify', $s) }}')"
                                class="w-full text-xs px-3 py-2 rounded" style="background:var(--surface-2, #f3f4f6); color:var(--text-primary, #111827); border:1px solid var(--border, #e5e7eb);">
                            {{ $s->suggested_deal_id ? 'File to a different deal…' : 'Link to a deal…' }}
                        </button>
                        <form method="POST" action="{{ route('corex.comms-suspense.dismiss', $s) }}"
                              onsubmit="return confirm('Reject this email? It will not be filed to any deal.');">
                            @csrf
                            <button type="submit" class="w-full text-xs px-3 py-2 rounded" style="background:transparent; color:#ef4444; border:1px solid rgba(239,68,68,0.3);">Reject</button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-md px-5 py-8 text-center text-sm" style="background:var(--surface, #fff); border:1px dashed var(--border, #e5e7eb); color:var(--text-muted, #6b7280);">
                Nothing to review — every attorney email has filed itself. 🎉
            </div>
        @endforelse
    </div>

    {{ $items->links() }}

    {{-- ── RECENTLY FILED (reassign if wrong) ── --}}
    @if($recent->isNotEmpty())
        <div class="rounded-md px-5 py-4 mt-6" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb);">
            <div class="text-xs font-semibold mb-2" style="color:var(--text-secondary, #374151);">Recently filed — reassign if it went to the wrong deal</div>
            <div class="space-y-2">
                @foreach($recent as $s)
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <div class="min-w-0">
                            <span style="color:var(--text-primary, #111827);">{{ \Illuminate\Support\Str::limit($s->communication?->subject ?: '(no subject)', 48) }}</span>
                            <span style="color:var(--text-muted, #6b7280);">→ {{ $s->resolvedDeal?->deal_no ? '#'.$s->resolvedDeal->deal_no : 'Deal '.$s->resolved_deal_id }}</span>
                        </div>
                        <button type="button" @click="open('{{ route('corex.comms-suspense.reassign', $s) }}')"
                                class="text-[11px] px-2 py-1 rounded shrink-0" style="background:var(--surface-2, #f3f4f6); color:var(--text-primary, #111827); border:1px solid var(--border, #e5e7eb);">Reassign…</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── DEAL PICKER (link / reassign) ── --}}
    <div x-show="picker" x-cloak @keydown.escape.window="picker=false"
         class="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4" style="background:rgba(0,0,0,0.4);">
        <div class="w-full max-w-lg rounded-md p-4" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb);" @click.outside="picker=false">
            <div class="flex items-center justify-between mb-2">
                <div class="text-sm font-semibold" style="color:var(--text-primary, #111827);">Pick the deal to file this correspondence to</div>
                <button type="button" @click="picker=false" style="color:var(--text-muted, #6b7280);">✕</button>
            </div>
            <input type="text" x-model="q" @input.debounce.300ms="search()" placeholder="Search by address, deal number, or seller…"
                   class="w-full text-sm px-3 py-2 rounded mb-2" style="background:var(--surface-2, #f3f4f6); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);">
            <div class="max-h-64 overflow-y-auto space-y-1">
                <template x-if="loading"><div class="text-xs px-2 py-2" style="color:var(--text-muted,#6b7280);">Searching…</div></template>
                <template x-for="r in results" :key="r.id">
                    <button type="button" @click="pick(r.id)" class="w-full text-left text-sm px-3 py-2 rounded" style="background:var(--surface-2, #f3f4f6); color:var(--text-primary, #111827);" x-text="r.label"></button>
                </template>
                <template x-if="!loading && q.length>=2 && results.length===0"><div class="text-xs px-2 py-2" style="color:var(--text-muted,#6b7280);">No matching deals.</div></template>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function commsSuspense() {
    return {
        picker: false, actionUrl: '', q: '', results: [], loading: false,
        open(url) { this.actionUrl = url; this.q = ''; this.results = []; this.picker = true; },
        async search() {
            if (this.q.length < 2) { this.results = []; return; }
            this.loading = true;
            try {
                const res = await fetch('{{ route('corex.comms-suspense.deal-search') }}?q=' + encodeURIComponent(this.q), { headers: { 'Accept': 'application/json' } });
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
