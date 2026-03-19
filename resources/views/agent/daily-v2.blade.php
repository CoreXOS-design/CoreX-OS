@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col" style="height: calc(100vh - 64px);" x-data="{ search: '' }">

    {{-- Compact Header Row: back + title + date picker + print --}}
    <div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-4 py-3 flex-shrink-0">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <a class="text-white/60 hover:text-white text-sm transition-all duration-300" href="{{ route('agent.daily.summary') }}">&larr;</a>
                <div>
                    <h2 class="text-base font-bold text-white leading-tight tracking-tight">Daily Activity</h2>
                    <div class="text-xs text-white/50">{{ $selectedDate }}</div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('agent.daily.print', ['date' => $selectedDate]) }}" target="_blank"
                   class="rounded-md border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-medium text-white transition-all duration-300 hover:bg-white/20">
                   Print
                </a>
                <form method="GET" action="{{ route('agent.daily') }}">
                    <input type="date" name="date" value="{{ $selectedDate }}"
                           class="rounded-md border-0 bg-white/10 text-white text-xs px-2.5 py-1 transition-all duration-300"
                           onchange="this.form.submit()" />
                </form>
            </div>
        </div>
    </div>

    {{-- Week strip + Monthly stats — 50/50 split --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-2 mt-2 flex-shrink-0">
        {{-- Week strip (left half) --}}
        @if(isset($agentDailyWeek) && isset($agentDailyWeek['days']))
            <div class="rounded-md px-3 py-2.5 flex items-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex flex-wrap gap-1.5 w-full">
                    @foreach($agentDailyWeek['days'] as $d)
                        <a href="{{ route('agent.daily', ['date' => $d['date']]) }}"
                           class="flex-1 text-center px-2 py-1.5 rounded-md text-xs font-medium transition-all duration-300"
                           style="{{ $d['is_selected']
                               ? 'background: var(--brand-default, #0b2a4a); color: #fff;'
                               : 'background: var(--surface-2); color: var(--text-primary);' }}"
                           onmouseover="{{ !$d['is_selected'] ? "this.style.background='var(--surface)'" : '' }}"
                           onmouseout="{{ !$d['is_selected'] ? "this.style.background='var(--surface-2)'" : '' }}">
                            {{ $d['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Monthly stats (right half) --}}
        <div class="rounded-md px-4 py-2.5" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
            <div class="grid grid-cols-4 gap-3 h-full items-center">
                <div class="text-center">
                    <div class="text-[10px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">Month</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--text-primary);">{{ $period }}</div>
                </div>
                <div class="text-center">
                    <div class="text-[10px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">Target</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--text-primary);">{{ (int)($monthlyTarget ?? 0) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-[10px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">MTD</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--brand-icon, #0ea5e9);">{{ (int)($mtdPoints ?? 0) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-[10px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">Remaining</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--text-primary);">{{ (int)($remainingPoints ?? 0) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Search Bar --}}
    <div class="relative mt-2 flex-shrink-0">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <svg class="w-3.5 h-3.5" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
        <input type="text"
               x-model="search"
               placeholder="Search activities..."
               class="w-full rounded-md pl-9 pr-9 py-2 text-sm transition-all duration-300"
               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
               onfocus="this.style.borderColor='var(--brand-icon)'" onblur="this.style.borderColor='var(--border)'" />
        <button x-show="search.length > 0" x-on:click="search = ''" x-cloak
                class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer"
                style="color: var(--text-muted);">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- Capture activity form — fills remaining space --}}
    <div class="rounded-md overflow-hidden mt-2 flex-1 flex flex-col min-h-0" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('agent.daily') }}" class="flex flex-col flex-1 min-h-0">
            @csrf
            <input type="hidden" name="activity_date" value="{{ $selectedDate }}"/>

            {{-- Table container — scrolls to fill remaining space --}}
            <div class="flex-1 overflow-y-auto min-h-0">
                <table class="min-w-full text-sm ds-table">
                    <thead class="sticky top-0 z-10">
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Activity</th>
                            <th class="text-right px-4 py-2 text-xs font-semibold uppercase tracking-wide w-20" style="color: var(--text-secondary);">Weight</th>
                            <th class="text-center px-4 py-2 text-xs font-semibold uppercase tracking-wide w-32" style="color: var(--text-secondary);">Qty</th>
                            <th class="text-right px-4 py-2 text-xs font-semibold uppercase tracking-wide w-20" style="color: var(--text-secondary);">Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($definitions as $def)
                            @php
                                $val = (int)($values[$def->id] ?? 0);
                                $pts = $val * (int)$def->weight;
                            @endphp
                            <tr class="transition-all duration-300" style="border-bottom: 1px solid var(--border);"
                                x-show="!search.trim() || '{{ strtolower(addslashes($def->name)) }}'.includes(search.toLowerCase().trim())"
                                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                <td class="px-4 py-2">
                                    <div class="font-medium text-sm" style="color: var(--text-primary);">{{ $def->name }}</div>
                                </td>
                                <td class="px-4 py-2 text-right" style="color: var(--text-secondary);">{{ (int)$def->weight }}</td>
                                <td class="px-4 py-2 text-center">
                                    @php($mode = (string)($def->scoring_mode ?? 'count'))
                                    @if($mode === 'once')
                                        <input type="hidden" name="values[{{ $def->id }}]" value="0">
                                        <input type="checkbox" name="values[{{ $def->id }}]" value="1"
                                               @checked($val > 0)
                                               class="h-5 w-5 rounded transition-all duration-300"
                                               style="border-color: var(--border);" />
                                    @else
                                        <input type="number" min="0" step="1"
                                               name="values[{{ $def->id }}]" value="{{ $val }}"
                                               class="w-20 rounded-md px-2 py-1.5 text-sm text-center transition-all duration-300"
                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                               onfocus="this.style.borderColor='var(--brand-button, #0ea5e9)'"
                                               onblur="this.style.borderColor='var(--border)'" />
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium" style="color: var(--text-primary);">{{ $pts }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center" style="color: var(--text-muted);">
                                    No enabled activity definitions found for your branch.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Footer: total + save — always visible --}}
            <div class="px-4 py-2.5 flex items-center justify-between flex-shrink-0" style="border-top: 1px solid var(--border);">
                <div>
                    <span class="text-sm font-medium" style="color: var(--text-primary);">Today:</span>
                    <span class="text-sm font-bold ml-1" style="color: var(--brand-icon, #0ea5e9);">{{ $totalPoints }} pts</span>
                </div>
                <button class="rounded-md px-5 py-2 text-sm font-semibold text-white transition-all duration-300 hover:opacity-90"
                        style="background: var(--brand-button, #0ea5e9);">
                    Save
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
