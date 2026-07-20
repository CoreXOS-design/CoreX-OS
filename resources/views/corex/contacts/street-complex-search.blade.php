{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-273 — Street & Complex Search results. Address-only search over the
     contact's Address + Linked Properties, tagged with Last Contacted / Last
     Modified / linked-property status. Downloadable as PDF. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-xs text-white/60 mb-1">
                    <a href="{{ route('corex.contacts.index') }}" class="no-underline hover:underline" style="color:rgba(255,255,255,0.6);">Contacts</a>
                    <span>/</span>
                    <span>Street &amp; Complex Search</span>
                </div>
                <h1 class="text-xl font-bold text-white leading-tight">Street &amp; Complex Search</h1>
                <p class="text-sm text-white/60">
                    @if($term !== '')
                        {{ $total }} {{ \Illuminate\Support\Str::plural('contact', $total) }} matching
                        <span class="font-semibold text-white">“{{ $term }}”</span>
                        by Address &amp; Linked Properties.
                    @else
                        Search contacts by street or complex name.
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if($term !== '' && $contacts->isNotEmpty())
                <a href="{{ route('corex.contacts.street-complex-search.pdf', array_filter(['q' => $term, 'sort' => $sort, 'dir' => $dir], fn($v) => $v !== null)) }}"
                   class="corex-btn-primary text-sm no-underline">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    Download PDF
                </a>
                @endif
                <a href="{{ route('corex.contacts.index') }}" class="corex-btn-outline text-sm no-underline">Back to Contacts</a>
            </div>
        </div>
    </div>

    {{-- Search box (re-run without leaving the report) --}}
    <form method="GET" action="{{ route('corex.contacts.street-complex-search') }}"
          class="rounded-md px-4 py-3 flex flex-wrap items-center gap-3"
          style="background:var(--surface);border:1px solid var(--border);">
        {{-- No agent_id hidden field: the property search always runs at the agency's
             full contact-visibility scope, so re-running it must not re-inject a
             per-agent narrowing (AT-273). --}}
        <div class="relative flex-1 min-w-[220px] max-w-md">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
            <input type="text" name="q" value="{{ $term }}" autofocus
                   placeholder="e.g. Seaview Estate or Marine Drive"
                   class="w-full pl-10 pr-3 py-2 text-sm rounded-md"
                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
        </div>

        {{-- Sort controls — submit the form on change so the order updates in place.
             Sorts on the contact's own address columns + the date tags. --}}
        <div class="flex items-center gap-2">
            <label class="text-xs font-semibold whitespace-nowrap" style="color:var(--text-muted);">Sort by</label>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter">
                @foreach($sortOptions as $key => $label)
                    <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Sort direction">
                <option value="asc"  {{ $dir === 'asc'  ? 'selected' : '' }}>A–Z / 0–9 / oldest</option>
                <option value="desc" {{ $dir === 'desc' ? 'selected' : '' }}>Z–A / 9–0 / newest</option>
            </select>
        </div>

        <button type="submit" class="corex-btn-primary text-sm">Search</button>
    </form>

    @if($capped)
    <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 12%, transparent);border:1px solid var(--ds-amber,#f59e0b);color:var(--text-primary);">
        Showing the first {{ number_format($cap) }} of {{ number_format($total) }} matches. Narrow the search term to see the rest.
    </div>
    @endif

    {{-- Results --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div class="text-sm font-bold" style="color:var(--text-primary);">
                Matching Contacts
                <span class="ml-1 text-xs font-normal" style="color:var(--text-muted);">({{ number_format($contacts->count()) }})</span>
            </div>
        </div>

        @forelse($contacts as $contact)
            @php
                $fullName    = trim($contact->first_name . ' ' . $contact->last_name) ?: '(no name)';
                $lastContact = $contact->last_contacted_at;
                $lastMod     = $contact->modified_at ?? $contact->updated_at;
                $residential = trim((string) $contact->address);
                $structured  = $contact->composeStructuredAddress();
                $linked      = $contact->properties;
            @endphp
            <div class="px-5 py-4" style="border-bottom:1px solid var(--border);">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">

                    {{-- Identity + addresses --}}
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ route('corex.contacts.show', $contact) }}"
                               class="text-sm font-bold no-underline hover:underline" style="color:var(--text-primary);">{{ $fullName }}</a>
                            @if($contact->type)
                            <span class="px-2 py-0.5 rounded-md text-[11px] font-semibold"
                                  style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);">{{ $contact->type->name }}</span>
                            @endif
                            {{-- Contact tags (e.g. Lead) — each its own pill, tinted with the
                                 tag's colour so it reads distinctly from the type badge. --}}
                            @foreach($contact->tags as $tag)
                                @php $tagColor = $tag->color ?: 'var(--brand-icon,#0ea5e9)'; @endphp
                                <span class="px-2 py-0.5 rounded-md text-[11px] font-semibold"
                                      style="background:color-mix(in srgb, {{ $tagColor }} 14%, transparent);color:{{ $tagColor }};border:1px solid color-mix(in srgb, {{ $tagColor }} 45%, transparent);">{{ $tag->name }}</span>
                            @endforeach
                        </div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                            Agent: {{ optional($contact->agent)->name ?? optional($contact->createdBy)->name ?? '—' }}
                        </div>

                        <div class="mt-2 space-y-1.5">
                            @if($residential !== '')
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-xs font-semibold flex-shrink-0" style="color:var(--text-muted);">Address:</span>
                                <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $residential }}</span>
                            </div>
                            @endif
                            @if($structured)
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-xs font-semibold flex-shrink-0" style="color:var(--text-muted);">Captured address:</span>
                                <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $structured }}</span>
                            </div>
                            @endif
                            @if($linked->isNotEmpty())
                            <div>
                                <span class="text-xs font-semibold" style="color:var(--text-muted);">Linked Properties:</span>
                                <ul class="mt-1 space-y-1.5">
                                    @foreach($linked as $property)
                                    @php
                                        $addr = $property->buildDisplayAddress();
                                        $unitLabel = filled($property->unit_number) ? 'Unit ' . trim((string) $property->unit_number)
                                            : (filled($property->unit_section_block) ? trim((string) $property->unit_section_block)
                                            : (filled($property->floor_number) ? 'Floor ' . trim((string) $property->floor_number) : null));
                                        $rest = $addr;
                                        if ($unitLabel && \Illuminate\Support\Str::startsWith($addr, $unitLabel)) {
                                            $rest = ltrim(\Illuminate\Support\Str::after($addr, $unitLabel), ', ');
                                        }
                                    @endphp
                                    <li class="flex items-baseline gap-1.5">
                                        <span class="flex-shrink-0" style="color:var(--brand-icon,#0ea5e9);">•</span>
                                        <span class="min-w-0">
                                            @if($unitLabel)
                                                <span class="text-base font-bold" style="color:var(--text-primary);">{{ $unitLabel }}</span>@if($rest !== '')<span class="text-sm font-semibold" style="color:var(--text-primary);">, {{ $rest }}</span>@endif
                                            @else
                                                <span class="text-sm font-semibold" style="color:var(--text-primary);">{{ $addr }}</span>
                                            @endif
                                            @if($property->pivot->role)
                                            <span class="text-[11px] font-normal" style="color:var(--text-muted);">({{ ucfirst($property->pivot->role) }})</span>
                                            @endif
                                        </span>
                                    </li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif
                            @if($residential === '' && ! $structured && $linked->isEmpty())
                            <div class="text-xs" style="color:var(--text-muted);">No address on record.</div>
                            @endif
                        </div>
                    </div>

                    {{-- Tags --}}
                    <div class="flex flex-row md:flex-col md:items-end gap-1.5 flex-wrap flex-shrink-0">
                        {{-- Linked-property status --}}
                        @if($linked->isNotEmpty())
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold"
                              style="background:color-mix(in srgb, var(--ds-emerald,#10b981) 15%, transparent);color:var(--ds-emerald,#10b981);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757"/></svg>
                            Linked ({{ $linked->count() }})
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold"
                              style="background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);">Not linked</span>
                        @endif

                        {{-- Last Contacted --}}
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-medium"
                              style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);"
                              title="Last contacted">
                            <span style="color:var(--text-muted);">Last contacted:</span>
                            {{ $lastContact ? $lastContact->format('d M Y') : 'Never' }}
                        </span>

                        {{-- Last Modified --}}
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-medium"
                              style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);"
                              title="Last modified">
                            <span style="color:var(--text-muted);">Last modified:</span>
                            {{ $lastMod ? $lastMod->format('d M Y') : '—' }}
                        </span>
                    </div>
                </div>
            </div>
        @empty
            <div class="px-5 py-12 text-center">
                @if($term === '')
                    <p class="text-sm" style="color:var(--text-muted);">Enter a street or complex name above to search.</p>
                @else
                    <p class="text-sm font-semibold" style="color:var(--text-primary);">No contacts match “{{ $term }}”.</p>
                    <p class="text-xs mt-1" style="color:var(--text-muted);">Nothing in any contact's Address or Linked Properties contains that term.</p>
                @endif
            </div>
        @endforelse
    </div>

</div>
@endsection
