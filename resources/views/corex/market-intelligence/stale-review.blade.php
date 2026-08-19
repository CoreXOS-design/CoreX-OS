{{-- MIC funnel phase 2 (Johan 2026-08-13) — BM/admin stale-claim review + reassignment.
     Anti-poaching: agents never grab stale stock; the BM/admin moves-or-keeps here. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="width:100%; max-width:1100px; margin:0 auto;">
    <div style="margin-bottom:1rem;">
        <h1 class="text-2xl font-bold" style="color:var(--text-primary);">Stale claims review</h1>
        <p class="text-sm" style="color:var(--text-muted);">
            Pitched/claimed properties sitting unworked. Warned at {{ $warnDays }} days; ready for
            move-or-keep at {{ $releaseDays }} days. Agents can't take these from each other — you decide.
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-3 py-2 mb-4 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="min-w-full text-sm ds-table">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Property</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">On it</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Unworked</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Move or keep</th>
                </tr>
            </thead>
            <tbody>
            @forelse($items as $it)
                @php $c = $it['claim']; @endphp
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-3">
                        <div class="font-medium" style="color:var(--text-primary);">{{ $it['address'] }}</div>
                        @if($c->release_reason)
                            <div class="text-xs" style="color:var(--ds-crimson,#c41e3a);">Released: {{ str_replace('_',' ',$c->release_reason) }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $it['agent_name'] }}</td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded-full font-bold"
                              style="background:color-mix(in srgb, {{ $it['is_stale'] ? 'var(--ds-crimson,#c41e3a)' : 'var(--ds-amber,#f59e0b)' }} 15%, transparent); color:{{ $it['is_stale'] ? 'var(--ds-crimson,#c41e3a)' : 'var(--ds-amber,#f59e0b)' }};">
                            {{ $it['days'] }} day{{ $it['days'] === 1 ? '' : 's' }}{{ $it['is_stale'] ? ' · STALE' : ' · warned' }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-2" x-data="{ mode:false }">
                            <form method="POST" action="{{ route('market-intelligence.stale-review.keep', $c->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded" style="border:1px solid var(--border); color:var(--text-secondary);">Keep</button>
                            </form>
                            <template x-if="!mode">
                                <button type="button" @click="mode=true" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background:var(--brand-button,#0ea5e9);">Reassign</button>
                            </template>
                            <template x-if="mode">
                                <form method="POST" action="{{ route('market-intelligence.stale-review.reassign', $c->id) }}" class="inline-flex items-center gap-2">
                                    @csrf
                                    <select name="new_agent_id" required class="text-xs rounded px-2 py-1.5" style="border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary);">
                                        <option value="">Reassign to…</option>
                                        @foreach($agents as $a)
                                            @if($a->id !== $c->user_id)<option value="{{ $a->id }}">{{ $a->name }}</option>@endif
                                        @endforeach
                                    </select>
                                    <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background:var(--ds-green,#059669);">Move</button>
                                    <button type="button" @click="mode=false" class="text-xs" style="color:var(--text-muted);">Cancel</button>
                                </form>
                            </template>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">No stale or warned claims — everyone's on top of their stock.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
