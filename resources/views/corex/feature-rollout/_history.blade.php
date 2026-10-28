{{-- AT-478 §12.10 — an agency's gradual-introduction history: every switch-on, change and access review, with
     who and when. Shared by Settings → Feature rollout (own agency) and the CoreX team's agency page.
     Expects: $history (LengthAwarePaginator from FeatureRolloutHistory), $historyFilters, $historyClearUrl.
     Its own query names (h_*, hpage) so it never disturbs the group list's filters on the same page. --}}
@php
    $hInput = 'rounded-md px-3 py-2 text-sm';
    $hStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $hBadge = ['switched_on' => 'ds-badge-success', 'changes' => 'ds-badge-info', 'access' => 'ds-badge-warning'];
@endphp
<div id="rollout-history" class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
        <div class="ds-section-header">History <span class="ds-badge ds-badge-muted ml-1">{{ $history->total() }}</span></div>
        <div class="ds-section-sub">Everything that switched on, every change to the plan and every check of who gets access — with who did it and when.</div>
    </div>
    <form method="GET" action="#rollout-history" class="px-5 py-3 flex flex-wrap items-end gap-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
        @foreach (request()->except(['h_kind', 'h_from', 'h_to', 'h_sort', 'hpage']) as $k => $v)
            @if (is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
        @endforeach
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Show</span>
            <select name="h_kind" class="block mt-1 {{ $hInput }}" style="{{ $hStyle }}">
                <option value="">Everything</option>
                @foreach (\App\Services\FeatureRollout\FeatureRolloutHistory::KINDS as $k => $l)
                    <option value="{{ $k }}" @selected(($historyFilters['kind'] ?? null) === $k)>{{ $l }}</option>
                @endforeach
            </select></label>
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">From</span>
            <input type="date" name="h_from" value="{{ $historyFilters['from'] ?? '' }}" class="block mt-1 {{ $hInput }}" style="{{ $hStyle }}"></label>
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">to</span>
            <input type="date" name="h_to" value="{{ $historyFilters['to'] ?? '' }}" class="block mt-1 {{ $hInput }}" style="{{ $hStyle }}"></label>
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Order</span>
            <select name="h_sort" class="block mt-1 {{ $hInput }}" style="{{ $hStyle }}">
                <option value="newest" @selected(($historyFilters['sort'] ?? 'newest') === 'newest')>Newest first</option>
                <option value="oldest" @selected(($historyFilters['sort'] ?? 'newest') === 'oldest')>Oldest first</option>
            </select></label>
        <button type="submit" class="corex-btn-outline">Apply</button>
        <a href="{{ $historyClearUrl }}#rollout-history" class="corex-btn-outline">Clear</a>
    </form>
    @forelse ($history as $h)
        <div class="px-5 py-3 flex flex-wrap items-start gap-3 text-sm" style="border-top: 1px solid var(--border);" data-history-row>
            <span class="text-xs tabular-nums whitespace-nowrap" style="color: var(--text-muted); min-width: 8.5rem;">{{ $h['when']->copy()->setTimezone(config('app.timezone'))->format('j M Y H:i') }}</span>
            <span class="ds-badge {{ $hBadge[$h['kind']] ?? 'ds-badge-muted' }}">{{ \App\Services\FeatureRollout\FeatureRolloutHistory::KINDS[$h['kind']] ?? '' }}</span>
            <span class="flex-1" style="color: var(--text-primary);">{{ $h['what'] }}</span>
            <span class="text-xs whitespace-nowrap" style="color: var(--text-secondary);">{{ $h['who'] }}</span>
        </div>
    @empty
        <div class="px-5 py-10 text-center text-sm" style="color: var(--text-muted);">
            {{ array_filter($historyFilters) !== [] && array_filter($historyFilters) !== ['sort' => 'newest'] ? 'Nothing matches these filters.' : 'Nothing has happened yet.' }}
        </div>
    @endforelse
    @if ($history->hasPages())
        <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $history->fragment('rollout-history')->links() }}</div>
    @endif
</div>
