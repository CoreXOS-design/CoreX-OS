@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="{
        search: '',
        get filteredCount() {
            if (!this.search.trim()) return {{ count($items) }};
            const q = this.search.toLowerCase().trim();
            return this.$refs.tbody ? this.$refs.tbody.querySelectorAll('tr[data-visible=\'true\']').length : {{ count($items) }};
        }
     }">

    {{-- Page Header --}}
    <div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="text-sm text-white/60">
                    <a class="hover:underline text-white/60 transition-all duration-300" href="{{ route('admin.dashboard') }}">&larr; Dashboard</a>
                </div>
                <h2 class="text-xl font-bold text-white leading-tight tracking-tight mt-1">Daily Activity Summary (Company)</h2>
                <div class="text-sm text-white/60">
                    {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </div>
            </div>

            <form method="GET" action="{{ route('admin.daily.summary') }}" class="flex flex-wrap items-center gap-2">
                <select name="range" class="rounded-md border border-white/20 bg-white/10 text-white text-sm px-3 py-1.5 transition-all duration-300 [&>option]:text-black">
                    <option value="7d"  {{ $range==='7d' ? 'selected' : '' }}>Last 7 days</option>
                    <option value="month" {{ $range==='month' ? 'selected' : '' }}>This month</option>
                    <option value="3m"  {{ $range==='3m' ? 'selected' : '' }}>Last 3 months</option>
                    <option value="6m"  {{ $range==='6m' ? 'selected' : '' }}>Last 6 months</option>
                    <option value="12m" {{ $range==='12m' ? 'selected' : '' }}>Last 12 months</option>
                </select>

                @if($range === 'month')
                    <input type="text" name="month" value="{{ $month ?? '' }}" placeholder="YYYY-MM"
                           class="w-28 rounded-md border border-white/20 bg-white/10 text-white text-sm px-3 py-1.5 placeholder:text-white/40 transition-all duration-300" />
                @endif

                <button class="corex-btn-primary text-sm">Apply</button>
            </form>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="ds-status-card">
            <div class="ds-label">Total Count</div>
            <div class="ds-value-xl">{{ (int)$grandCount }}</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Total Points</div>
            <div class="ds-value-xl">{{ number_format((float)$grandPoints, 0) }}</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Activities Tracked</div>
            <div class="ds-value-xl">{{ count($items) }}</div>
        </div>
    </div>

    {{-- Search Bar --}}
    <div class="relative">
        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
            <svg class="w-4 h-4" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
        <input type="text"
               x-model="search"
               placeholder="Search activities..."
               class="w-full rounded-md pl-10 pr-10 py-2.5 text-sm transition-all duration-300"
               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
               onfocus="this.style.borderColor='var(--brand-icon)'" onblur="this.style.borderColor='var(--border)'" />
        <button x-show="search.length > 0" x-on:click="search = ''" x-cloak
                class="absolute inset-y-0 right-0 pr-4 flex items-center cursor-pointer"
                style="color: var(--text-muted);">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- By Activity Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <div>
                <h3 class="text-sm font-semibold" style="color: var(--text-primary);">By Activity</h3>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Click the activity name or count to drill down to branches.</div>
            </div>
            <div x-show="search.length > 0" x-cloak class="text-xs rounded-md px-2.5 py-1" style="background: var(--surface-2); color: var(--text-secondary);">
                Showing matching results
            </div>
        </div>

        <div class="overflow-x-auto" style="max-height: 600px; overflow-y: auto;">
            <table class="min-w-full text-sm ds-table">
                <thead class="sticky top-0 z-10">
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Activity</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Count</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Points</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">% (Points)</th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @foreach($items as $it)
                        <tr class="transition-all duration-300" style="border-bottom: 1px solid var(--border);"
                            x-show="!search.trim() || '{{ strtolower(addslashes($it['name'])) }}'.includes(search.toLowerCase().trim())"
                            x-bind:data-visible="(!search.trim() || '{{ strtolower(addslashes($it['name'])) }}'.includes(search.toLowerCase().trim())).toString()"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                            <td class="px-4 py-2.5 font-medium">
                                <a class="hover:underline transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);"
                                   href="{{ route('admin.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ $it['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <a class="inline-flex items-center rounded-md px-2.5 py-1 font-semibold text-xs transition-all duration-300"
                                   style="background: var(--surface-2); color: var(--text-primary);"
                                   href="{{ route('admin.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ (int)$it['count'] }}
                                </a>
                            </td>
                            <td class="px-4 py-2.5 text-right" style="color: var(--text-primary);">{{ number_format((float)$it['points'], 0) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color: var(--text-secondary);">{{ number_format((float)$it['pct_points'], 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- No Results Message --}}
        <div x-show="search.trim() && !$refs.tbody.querySelector('tr[data-visible=\'true\']')" x-cloak
             class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">
            No activities match "<span x-text="search"></span>"
        </div>
    </div>

</div>
@endsection
