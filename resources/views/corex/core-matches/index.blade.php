@extends('layouts.corex')

@section('corex-content')
@php
    $totalMatches = $contacts->sum(fn($row) => $row['matches']->count());
@endphp
<div class="space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Core Matches</h1>
                <p class="text-sm text-white/60">Buyer and renter search criteria saved against your contacts.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.contacts.index') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    Contacts
                </a>
                @permission('core_matches.all_view')
                <a href="{{ route('corex.core-matches.all') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    All View
                </a>
                @endpermission
                @permission('access_settings')
                <a href="{{ url('/corex/settings?s=feature-matches') }}"
                   title="Core Matches Settings"
                   aria-label="Core Matches Settings"
                   class="inline-flex items-center justify-center rounded-md text-white transition-colors"
                   style="width:30px; height:30px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    @if($totalMatches > 0)
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Saved searches" :value="number_format($totalMatches)" />
        <x-corex-kpi-card title="Contacts with criteria" :value="number_format($contacts->count())" />
    </div>
    @endif

    @if($contacts->isEmpty())
    {{-- Empty state --}}
    <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No Core Matches saved yet</h3>
        <p class="text-sm mb-4" style="color:var(--text-muted);">Open a contact and go to the Core Matches tab to save buyer or renter criteria.</p>
        <a href="{{ route('corex.contacts.index') }}" class="corex-btn-primary text-sm">Go to Contacts</a>
    </div>

    @else
    <div class="space-y-3">
        @foreach($contacts as $row)
        @php
            $contact = $row['contact'];
            $matches = $row['matches'];
        @endphp

        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">

            {{-- Contact header --}}
            <div class="flex items-center justify-between gap-3 px-5 py-4"
                 style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                <div class="flex items-center gap-3 min-w-0">
                    {{-- Avatar --}}
                    <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                         style="background:var(--brand-icon,#0ea5e9);">
                        {{ $contact->initials }}
                    </div>
                    {{-- Name + meta --}}
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
                               class="text-sm font-semibold no-underline leading-tight transition-colors duration-150"
                               style="color:var(--text-primary);">
                                {{ $contact->full_name }}
                            </a>
                            @if($contact->type)
                            <span class="text-xs px-2 py-0.5 rounded-md font-medium flex-shrink-0 whitespace-nowrap"
                                  style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);">
                                {{ $contact->type->name }}
                            </span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                            @if($contact->phone)
                            <span class="text-xs" style="color:var(--text-secondary);">{{ $contact->phone }}</span>
                            @endif
                            @if($contact->email)
                            <span class="text-xs" style="color:var(--text-secondary);">{{ $contact->email }}</span>
                            @endif
                        </div>
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
                <div class="flex items-center gap-4 px-5 py-3.5 flex-wrap"
                     style="{{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">

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

                        @if(!$match->category && !$match->property_type && !$match->suburb && !$match->price_min && !$match->price_max && !$match->beds_min && !$match->baths_min)
                        <span class="text-xs italic" style="color:var(--text-muted);">Any property</span>
                        @endif
                    </div>

                    {{-- Match counts: total / visible / hidden --}}
                    @php $counts = $matchCounts[$match->id] ?? ['total' => 0, 'visible' => 0, 'hidden' => 0]; @endphp
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
