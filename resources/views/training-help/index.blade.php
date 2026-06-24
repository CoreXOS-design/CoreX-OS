@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5" x-data="trainingIndex()">

    {{-- Page header (branded — Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight" data-tour="train-help-intro">Training Centre</h1>
                <p class="text-sm text-white/60">Learn how to use CoreX — step-by-step guides for every role.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Search trigger --}}
                <button @click="searchOpen = true"
                        data-tour="train-help-search"
                        class="corex-btn-outline text-sm"
                        style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                    <span>Search docs</span>
                    <kbd class="hidden sm:inline text-[0.6875rem] px-1 rounded" style="background:rgba(255,255,255,0.12); color:#fff; border:1px solid rgba(255,255,255,0.25);">/</kbd>
                </button>
            </div>
        </div>
    </div>

    {{-- Required docs progress --}}
    <div class="rounded-md p-4 flex flex-wrap items-center justify-between gap-3"
         data-tour="train-help-progress"
         style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Required Docs Progress</div>
            <div class="text-sm mt-0.5" style="color:var(--text-secondary);">{{ number_format($requiredDone) }} of {{ number_format($requiredTotal) }} completed for your role</div>
        </div>
        <div class="flex items-center gap-3" style="min-width:200px;">
            <div class="flex-1 h-2 rounded overflow-hidden" style="background:var(--surface-2);">
                <div class="h-full rounded transition-all" style="width:{{ $overallProgress }}%; background:var(--brand-icon, #0ea5e9);"></div>
            </div>
            <span class="text-sm font-semibold whitespace-nowrap" style="color:var(--text-primary);">{{ $overallProgress }}%</span>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-2" data-tour="train-help-filters">
        @php $filters = [
            'all' => 'All',
            'for-me' => 'For Me',
            'admin' => 'Admin',
            'branch_manager' => 'BM',
            'agent' => 'Agent',
            'compliance_officer' => 'CO',
            'super_admin' => 'Owner',
        ]; @endphp
        @foreach($filters as $key => $label)
            <a href="{{ route('training-help.index', ['filter' => $key]) }}"
               class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
               style="{{ $filter === $key ? 'background:var(--brand-icon, #0ea5e9); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary);' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Required unread warning --}}
    @if($requiredUnread > 0)
    <div class="px-4 py-3 rounded-md flex items-center gap-3"
         style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color:var(--ds-amber, #f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
        <span class="text-sm font-medium" style="color:var(--text-primary);">{{ number_format($requiredUnread) }} required {{ Str::plural('guide', $requiredUnread) }} not yet completed for your role.</span>
    </div>
    @endif

    {{-- Doc Cards Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4" data-tour="train-help-grid">
        @forelse($docs as $doc)
        @php
            $read = $reads->get($doc->id);
            $progress = $doc->getProgressForUser(auth()->id());
            $isOutdated = $read?->is_outdated_since;
            $isDone = $read?->completed_at && !$isOutdated;
            $isStarted = $read && $progress > 0 && !$isDone;
            $isRequired = $doc->isRequiredForRole($role);
        @endphp
        <a href="{{ route('training-help.show', $doc->slug) }}"
           @if($loop->first) data-tour="train-help-card" @endif
           class="block rounded-md p-5 transition-all group"
           style="background:var(--surface); border:1px solid var(--border);">
            <div class="flex items-start justify-between gap-2 mb-3">
                <h3 class="text-sm font-semibold leading-tight group-hover:underline" style="color:var(--text-primary);">
                    {{ $doc->title }}
                </h3>
                @if($isRequired)
                <span class="flex-shrink-0 px-1.5 py-0.5 rounded-md text-[0.6875rem] font-bold uppercase whitespace-nowrap"
                      style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color:var(--brand-icon, #0ea5e9);">Required</span>
                @endif
            </div>

            <div class="flex items-center gap-3 text-xs mb-3" style="color:var(--text-muted);">
                <span>For: {{ ucwords(str_replace('_', ' ', $doc->role_audience)) }}</span>
                <span>{{ number_format($doc->reading_time) }} min read</span>
                <span>{{ number_format($doc->word_count) }} words</span>
            </div>

            @if($isOutdated)
            <div class="mb-2 px-2 py-1 rounded-md text-[0.6875rem] font-medium"
                 style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b);">
                Updated since you last read — re-review needed
            </div>
            @endif

            {{-- Progress bar --}}
            <div class="mb-3">
                <div class="w-full h-1 rounded overflow-hidden" style="background:var(--surface-2);">
                    <div class="h-full rounded transition-all"
                         style="width:{{ $progress }}%; background:{{ $isDone ? 'var(--ds-green, #059669)' : 'var(--brand-icon, #0ea5e9)' }};"></div>
                </div>
                <div class="text-xs mt-1 font-medium" style="color:var(--text-muted);">{{ number_format($progress) }}% complete</div>
            </div>

            <span class="text-xs font-medium" style="color:var(--brand-icon, #0ea5e9);">
                @if($isDone) Re-read @elseif($isStarted) Continue reading @else Start @endif →
            </span>
        </a>
        @empty
        {{-- Empty state --}}
        <div class="md:col-span-2 lg:col-span-3 xl:col-span-4 rounded-md py-12 px-6 text-center"
             style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" /></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No training guides yet</h3>
            <p class="text-sm" style="color:var(--text-muted);">There are no guides matching this filter. Try a different role filter above.</p>
        </div>
        @endforelse
    </div>

    {{-- Bookmarks Section --}}
    @if($bookmarks->isNotEmpty())
    <div>
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Your Bookmarks</h2>
        <div class="space-y-2">
            @foreach($bookmarks as $bm)
            <a href="{{ route('training-help.show', $bm->doc->slug) }}#{{ $bm->section_anchor }}"
               class="flex items-center gap-3 px-4 py-2.5 rounded-md transition-colors hover:opacity-80"
               style="background:var(--surface); border:1px solid var(--border);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon, #0ea5e9);"><path fill-rule="evenodd" d="M6.32 2.577a49.255 49.255 0 0 1 11.36 0c1.497.174 2.57 1.46 2.57 2.93V21a.75.75 0 0 1-1.085.67L12 18.089l-7.165 3.583A.75.75 0 0 1 3.75 21V5.507c0-1.47 1.073-2.756 2.57-2.93Z" clip-rule="evenodd" /></svg>
                <div>
                    <span class="text-sm" style="color:var(--text-primary);">{{ $bm->section_anchor }}</span>
                    <span class="text-xs ml-2" style="color:var(--text-muted);">— {{ $bm->doc->title }}</span>
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Search Modal --}}
    <div x-show="searchOpen" x-cloak x-transition.opacity
         @keydown.escape.window="searchOpen = false"
         @keydown.ctrl.191.window.prevent="searchOpen = !searchOpen"
         @keydown.slash.window="if(!['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)){$event.preventDefault(); searchOpen = !searchOpen}"
         class="fixed inset-0 z-50 flex items-start justify-center pt-[15vh]"
         style="background:rgba(0,0,0,0.5);">
        <div @click.outside="searchOpen = false"
             class="w-full max-w-xl rounded-md overflow-hidden"
             style="background:var(--surface); border:1px solid var(--border); box-shadow:0 10px 30px rgba(0,0,0,0.18);">
            <div class="flex items-center gap-3 px-4 py-3" style="border-bottom:1px solid var(--border);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input x-ref="searchInput" x-init="$watch('searchOpen', v => v && $nextTick(() => $refs.searchInput.focus()))"
                       x-model="searchQuery" @input.debounce.300ms="doSearch()"
                       type="text" placeholder="Search training docs..."
                       class="flex-1 bg-transparent text-sm outline-none" style="color:var(--text-primary);">
                <kbd class="text-[0.6875rem] px-1.5 py-0.5 rounded" style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Esc</kbd>
            </div>
            <div class="max-h-80 overflow-y-auto">
                <template x-if="searchLoading">
                    <div class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">Searching...</div>
                </template>
                <template x-if="!searchLoading && searchResults.length === 0 && searchQuery.length >= 2">
                    <div class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">No results found.</div>
                </template>
                <template x-for="(result, idx) in searchResults" :key="idx">
                    <a :href="result.url" class="block px-4 py-3 transition-colors hover:opacity-80" style="border-bottom:1px solid var(--border);">
                        <div class="text-sm font-medium" style="color:var(--text-primary);" x-text="result.doc_title"></div>
                        <div class="text-xs mt-0.5" style="color:var(--brand-icon, #0ea5e9);" x-text="result.section"></div>
                        <div class="text-xs mt-1 line-clamp-2" style="color:var(--text-muted);" x-text="result.snippet"></div>
                    </a>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function trainingIndex() {
    return {
        searchOpen: false,
        searchQuery: '',
        searchResults: [],
        searchLoading: false,
        async doSearch() {
            if (this.searchQuery.length < 2) { this.searchResults = []; return; }
            this.searchLoading = true;
            try {
                const res = await fetch(`{{ route('training-help.search') }}?q=${encodeURIComponent(this.searchQuery)}`);
                const data = await res.json();
                this.searchResults = data.results || [];
            } catch (e) {
                this.searchResults = [];
            }
            this.searchLoading = false;
        }
    };
}
</script>
@endsection
