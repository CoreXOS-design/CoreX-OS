@extends('layouts.corex')

@section('corex-content')
@php
    $totalMatches = $byAgent->sum(fn($row) => $row['matches']->count());
@endphp
<div class="space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">All Core Matches</h1>
                <p class="text-sm text-white/60">
                    Every saved search across {{ $branchLimited ? 'your branch' : 'the agency' }} — oversight for managers and admins.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.core-matches.index') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    My Core Matches
                </a>
            </div>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.core-matches.all') }}"
              class="flex items-end gap-3 flex-wrap">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color:var(--text-secondary);">Filter by agent</label>
                <select name="agent_id" onchange="this.form.submit()"
                        class="rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:220px;">
                    <option value="all" @selected($agentId === null)>All agents</option>
                    @foreach($agents as $agent)
                    <option value="{{ $agent->id }}" @selected($agentId === (int) $agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($agentId !== null)
            <a href="{{ route('corex.core-matches.all') }}" class="corex-btn-outline text-sm">Clear filter</a>
            @endif
            <div class="flex-1"></div>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-md whitespace-nowrap"
                  style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
                {{ number_format($totalMatches) }} {{ Str::plural('search', $totalMatches) }}
            </span>
        </form>
    </div>

    @if($byAgent->isEmpty())
    {{-- Empty state --}}
    <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No Core Matches found</h3>
        <p class="text-sm" style="color:var(--text-muted);">
            No saved searches match the current filter{{ $branchLimited ? ' in your branch' : '' }}.
        </p>
    </div>

    @else
    <div class="space-y-3">
        @foreach($byAgent as $row)
        @php
            $agent   = $row['agent'];
            $matches = $row['matches'];
        @endphp

        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">

            {{-- Agent header --}}
            <div class="flex items-center justify-between gap-3 px-5 py-4"
                 style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                         style="background:var(--brand-icon,#0ea5e9);">
                        {{ strtoupper(mb_substr($agent->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-semibold leading-tight" style="color:var(--text-primary);">
                            {{ $agent->name ?? 'Unknown agent' }}
                        </div>
                        @if($agent && $agent->email)
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $agent->email }}</div>
                        @endif
                    </div>
                </div>
                <div class="flex-shrink-0">
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-md whitespace-nowrap"
                          style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
                        {{ number_format($matches->count()) }} {{ Str::plural('search', $matches->count()) }}
                    </span>
                </div>
            </div>

            {{-- Match rows --}}
            <div>
                @foreach($matches as $match)
                @php
                    $contact = $match->contact;
                    $counts  = $matchCounts[$match->id] ?? ['total' => 0, 'visible' => 0, 'hidden' => 0];
                @endphp
                <div class="flex items-center gap-4 px-5 py-3.5 flex-wrap"
                     style="{{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">

                    {{-- Contact --}}
                    <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
                       class="text-sm font-semibold no-underline flex-shrink-0 whitespace-nowrap"
                       style="color:var(--text-primary); min-width:140px;">
                        {{ $contact->full_name }}
                    </a>

                    {{-- Type pill --}}
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-md flex-shrink-0 whitespace-nowrap"
                          style="{{ $match->listing_type === 'rental'
                              ? 'background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);'
                              : 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 22%, transparent);' }}">
                        {{ $match->listingTypeLabel() }}
                    </span>

                    {{-- Criteria --}}
                    <div class="flex items-center gap-1.5 flex-wrap flex-1 min-w-0">
                        @if($match->price_min || $match->price_max)
                        <span class="text-xs font-bold" style="color:var(--text-primary);">{{ $match->priceRangeLabel() }}</span>
                        <span class="text-xs" style="color:var(--text-muted);">·</span>
                        @endif
                        @if($match->suburb)
                        <span class="text-xs font-medium" style="color:var(--text-secondary);">📍 {{ $match->suburb }}</span>
                        <span class="text-xs" style="color:var(--text-muted);">·</span>
                        @endif
                        @if($match->category)
                        <span class="text-xs px-2 py-0.5 rounded-md font-medium" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">{{ $match->category }}</span>
                        @endif
                        @if($match->property_type)
                        <span class="text-xs px-2 py-0.5 rounded-md font-medium" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">{{ $match->property_type }}</span>
                        @endif
                        @foreach([[$match->beds_min,'Beds'],[$match->baths_min,'Baths'],[$match->garages_min,'Gar']] as [$val,$lbl])
                        @if($val !== null)
                        <span class="text-xs px-2 py-0.5 rounded-md font-medium" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">{{ $val }}+ {{ $lbl }}</span>
                        @endif
                        @endforeach
                    </div>

                    {{-- Match counts: total / visible / hidden --}}
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                              style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                              title="Total properties matching this search">
                            {{ number_format($counts['total']) }} {{ Str::plural('match', $counts['total']) }}
                        </span>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                              style="background:color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent); color:var(--ds-green, #16a34a); border:1px solid color-mix(in srgb, var(--ds-green, #16a34a) 25%, transparent);"
                              title="Visible to the client">
                            {{ number_format($counts['visible']) }} visible
                        </span>
                        @if($counts['hidden'] > 0)
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                              style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);"
                              title="Hidden from this match">
                            {{ number_format($counts['hidden']) }} hidden
                        </span>
                        @endif
                    </div>

                    {{-- Action --}}
                    <a href="{{ route('corex.contacts.matches.results', [$contact, $match]) }}"
                       class="corex-btn-outline text-xs flex-shrink-0 whitespace-nowrap inline-flex items-center gap-1.5">
                        View Matches
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>
                @endforeach
            </div>

        </div>
        @endforeach
    </div>
    @endif

</div>
@endsection
