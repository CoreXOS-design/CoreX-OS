{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="re-core-matches-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Core Matches</h1>
                <p class="text-xs" style="color: var(--text-muted);">Buyer and renter search criteria saved against your contacts.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Listing-type lens — a static label, honest not decorative, on a
                     Rentals entry point (server-side locked, see controller). A
                     lit-on-every-row type pill further down is suppressed for the
                     same reason once this lock is active. --}}
                @if($isRentalEntry)
                <span class="corex-btn-outline text-sm" style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08); cursor:default;" title="This entry point always shows rental searches only">Rentals only</span>
                @else
                <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    @foreach(['' => 'All', 'sale' => 'Sales', 'rental' => 'Rentals'] as $val => $label)
                    <a href="{{ request()->fullUrlWithQuery(['listing_type' => $val ?: null]) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="{{ !$loop->first ? 'border-left: 1px solid var(--border);' : '' }} {{ ($listingType ?? '') === $val ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">{{ $label }}</a>
                    @endforeach
                </div>
                @endif
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('corex.contacts.index') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    Contacts
                </a>
                @permission('access_settings')
                <a href="{{ url('/corex/settings?s=feature-matches') }}"
                   title="Core Matches Settings" aria-label="Core Matches Settings"
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
                {{-- Findability fix, 2026-09-29 (Johan, revised) — Johan couldn't find
                     "which statuses are included in Core Matches" (the gear above opens
                     the unrelated WhatsApp/message settings). Setting now lives in main
                     Settings' own Core Matches section (moved off Contact Governance).
                     Same permission as the setting itself (command_center.settings, per
                     the route's own middleware) — only users who can actually edit it
                     see the link. --}}
                @permission('command_center.settings')
                <a href="{{ url('/corex/settings?s=core-matches') }}"
                   title="Which statuses are shown in Core Matches" aria-label="Which statuses are shown in Core Matches"
                   class="inline-flex items-center justify-center rounded-md text-white transition-colors"
                   style="width:30px; height:30px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- Scope selector — only rendered when the viewer actually holds more
         than one option (core_matches.all_view). An agent with no oversight
         permission never sees a control offering a scope they can't use. --}}
    @if(count($availableScopes) > 1)
    <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
        @foreach($availableScopes as $s)
        <a href="{{ request()->fullUrlWithQuery(['scope' => $s, 'agent_id' => null]) }}"
           class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
           style="{{ !$loop->first ? 'border-left: 1px solid var(--border);' : '' }} {{ $scope === $s ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">
            {{ match($s) { 'own' => 'Mine', 'branch' => 'Branch', 'agency' => 'Agency', default => ucfirst($s) } }}
        </a>
        @endforeach
    </div>
    @endif

    {{-- Search / filter / sort bar --}}
    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" class="flex items-end gap-3 flex-wrap">
            @if($isRentalEntry)<input type="hidden" name="listing_type" value="rental">@endif
            @if(count($availableScopes) > 1)<input type="hidden" name="scope" value="{{ $scope }}">@endif

            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color:var(--text-secondary);">Search</label>
                <input type="text" name="q" value="{{ $search }}" placeholder="Name, phone or email" data-tour="re-core-matches-search"
                       class="rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:200px;">
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color:var(--text-secondary);">Status</label>
                <select name="status" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm" data-tour="re-core-matches-status"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:130px;">
                    <option value="" @selected($statusFilter === '')>Any status</option>
                    @foreach(['active' => 'Active', 'paused' => 'Paused', 'fulfilled' => 'Fulfilled', 'expired' => 'Expired'] as $val => $label)
                    <option value="{{ $val }}" @selected($statusFilter === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color:var(--text-secondary);">Saved from</label>
                <input type="date" name="saved_from" value="{{ $savedFrom }}" onchange="this.form.submit()"
                       class="rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color:var(--text-secondary);">Saved to</label>
                <input type="date" name="saved_to" value="{{ $savedTo }}" onchange="this.form.submit()"
                       class="rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            @if($scope !== 'own')
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color:var(--text-secondary);">Agent</label>
                <select name="agent_id" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:180px;">
                    <option value="all" @selected($agentId === null)>All agents</option>
                    @foreach($agents as $agent)
                    <option value="{{ $agent->id }}" @selected($agentId === (int) $agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color:var(--text-secondary);">Sort</label>
                <select name="sort" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:170px;">
                    <option value="priority" @selected($sort === 'priority')>Status (default)</option>
                    <option value="saved" @selected($sort === 'saved')>Most recently saved</option>
                    <option value="contact" @selected($sort === 'contact')>Longest since contact</option>
                </select>
            </div>

            <button type="submit" class="corex-btn-primary text-sm" data-tour="re-core-matches-apply">Apply</button>
            @if($search !== '' || $statusFilter !== '' || $savedFrom !== '' || $savedTo !== '' || $agentId !== null || $sort !== 'priority')
            <a href="{{ request()->url() }}{{ $isRentalEntry ? '?listing_type=rental' : '' }}" class="corex-btn-outline text-sm">Clear</a>
            @endif

            <div class="flex-1"></div>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-md whitespace-nowrap"
                  style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
                {{ number_format($totalMatches) }} {{ Str::plural('search', $totalMatches) }} · {{ number_format($contacts->total()) }} {{ Str::plural('contact', $contacts->total()) }}
            </span>
        </form>
    </div>

    @if($contacts->total() === 0 && $search === '' && $statusFilter === '' && $savedFrom === '' && $savedTo === '' && $agentId === null)
    {{-- Genuinely nothing yet — different message from "no results for this filter". --}}
    <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
            {{ $scope === 'own' ? 'No Core Matches saved yet' : 'No Core Matches found' . ($scope === 'branch' ? ' in your branch' : ' in your agency') }}
        </h3>
        @if($scope === 'own')
        <p class="text-sm mb-4" style="color:var(--text-muted);">Open a contact and go to the Core Matches tab to save buyer or renter criteria.</p>
        <a href="{{ route('corex.contacts.index') }}" class="corex-btn-primary text-sm">Go to Contacts</a>
        @endif
    </div>
    @elseif($rows->isEmpty())
    {{-- Matches exist, but none match the current filter. --}}
    <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No Core Matches match this filter</h3>
        <p class="text-sm" style="color:var(--text-muted);">Try clearing a filter or searching a different term.</p>
    </div>

    @else
    <div class="space-y-3">
        @foreach($rows as $row)
        @php
            $contact = $row['contact'];
            $matches = $row['matches'];
            $isFirstCard = $loop->first;
        @endphp

        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);" @if($loop->first) data-tour="re-core-matches-card" @endif>

            {{-- Contact header — identity + facts that belong to the CONTACT,
                 never repeated once per search below it. --}}
            <div class="flex items-center justify-between gap-3 px-5 py-4"
                 style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                         style="background:var(--brand-icon,#0ea5e9);">
                        {{ $contact->initials }}
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
                               class="text-sm font-semibold no-underline leading-tight transition-colors duration-150"
                               style="color:var(--text-primary);">{{ $contact->full_name }}</a>
                            <x-buyer-state-chip :state="$contact->buyer_state" />
                            @if($contact->type)
                            <span class="text-xs px-2 py-0.5 rounded-md font-medium flex-shrink-0 whitespace-nowrap"
                                  style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);">
                                {{ $contact->type->name }}
                            </span>
                            @endif
                            {{-- Last contact — ONE value, one form (relative), the
                                 absolute date on hover rather than shown twice. --}}
                            @if($contact->last_contacted_at)
                            <span class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap"
                                  style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                  title="Last contacted {{ $contact->last_contacted_at->format('d M Y, H:i') }}">
                                Contacted {{ $contact->last_contacted_at->diffForHumans() }}
                            </span>
                            @else
                            <span class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap"
                                  style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
                                Never contacted
                            </span>
                            @endif
                            {{-- Notes — reachable in one click, never inline-dumped;
                                 absent entirely when there are none. Opens in a
                                 popup (Johan) rather than redirecting off the
                                 board and losing the filters — read-only, fetched
                                 on demand, never pre-loaded for every row. --}}
                            @if($contact->contact_notes_count > 0)
                            <button type="button" x-data
                               @click="$dispatch('open-notes-quick-view', { url: '{{ route('corex.contacts.notes.quick-view', $contact) }}' })"
                               class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap inline-flex items-center gap-1 border-0 cursor-pointer"
                               style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                               title="Read the notes on this contact">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                {{ $contact->contact_notes_count }} {{ Str::plural('note', $contact->contact_notes_count) }}
                            </button>
                            @endif
                            {{-- Add a note / mark contacted — the SAME contact-notes endpoint the
                                 contact screen posts to (ContactNoteController::store), so the note
                                 lands on the buyer's own notes and "Contacted and note" updates
                                 Last Contacted exactly as it does there. One shared modal below. --}}
                            @if(in_array($contact->id, $noteableContactIds, true))
                            <button type="button" x-data
                               @click="$dispatch('open-core-match-note', { action: '{{ route('corex.contacts.notes.store', $contact) }}', name: @js($contact->full_name) })"
                               class="text-xs px-2 py-0.5 rounded-md font-semibold whitespace-nowrap inline-flex items-center gap-1 cursor-pointer"
                               style="background:var(--surface); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 35%, transparent);"
                               title="Add a note">+ Note</button>
                            @endif
                            {{-- Update buyer pipeline — moves the buyer on the Buyer Pipeline board itself:
                                 same endpoints, same statuses, same Lost-reason dialog (one shared popup
                                 below). Shown only for contacts the viewer could move on that board. --}}
                            @if(in_array($contact->id, $pipelineMovableContactIds, true))
                            <button type="button" x-data
                               @click="$dispatch('open-core-match-pipeline', {
                                   name: @js($contact->full_name),
                                   state: @js($contact->buyer_state),
                                   noun: @js($matches->contains(fn ($m) => $m->listing_type !== 'rental') ? 'buyer' : 'tenant'),
                                   stateUrl: @js(route('command-center.buyers.update-state', $contact)),
                                   lostUrl: @js(route('command-center.buyers.mark-lost', $contact)),
                               })"
                               class="text-xs px-2 py-0.5 rounded-md font-semibold whitespace-nowrap inline-flex items-center gap-1 cursor-pointer"
                               style="background:var(--surface); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 35%, transparent);"
                               title="Move this {{ $matches->contains(fn ($m) => $m->listing_type !== 'rental') ? 'buyer' : 'tenant' }} to a different Buyer Pipeline status">Update buyer pipeline</button>
                            @endif
                            {{-- Move buyer to another agent — managers only (core_matches.reassign), for
                                 contacts the viewer can reach under their own/branch/agency scope. Moves the
                                 primary agent AND all of the buyer's saved searches (one shared popup). --}}
                            @if(in_array($contact->id, $reassignableContactIds, true))
                            <button type="button" x-data
                               @click="$dispatch('open-move-buyer', {
                                   name: @js($contact->full_name),
                                   current: @js(optional($moveBuyerAgents->firstWhere('id', $contact->agent_id))->name),
                                   action: @js(route('corex.core-matches.reassign-buyer', $contact)),
                                   currentId: {{ (int) $contact->agent_id }},
                               })"
                               class="text-xs px-2 py-0.5 rounded-md font-semibold whitespace-nowrap inline-flex items-center gap-1 cursor-pointer"
                               style="background:var(--surface); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 35%, transparent);"
                               title="Move this buyer, and all their saved searches, to another agent">Move buyer</button>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                            @if($contact->phone)<span class="text-xs" style="color:var(--text-secondary);">{{ $contact->phone }}</span>@endif
                            @if($contact->email)<span class="text-xs" style="color:var(--text-secondary);">{{ $contact->email }}</span>@endif
                            {{-- Ruling A (Johan, 2026-10-07): the first agent to receive this buyer's
                                 lead is their primary agent; leads they also sent to other agents are
                                 shown as information only — never as "moved" or "reassigned". --}}
                            @if($alsoEnquiredByContact->has($contact->id))
                            <span class="text-xs" style="color:var(--text-muted);" title="This buyer also sent enquiries to these agents' listings. Their primary agent has not changed.">
                                Also enquired with {{ $alsoEnquiredByContact->get($contact->id)->implode(', ') }}
                            </span>
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

            {{-- Properties the lead(s) came in on — Johan's own addition.
                 Shown once per contact (it's a fact about the buyer's
                 enquiry history, not about any one saved search), styled
                 like the existing suburb-pin convention, compact. Absent
                 entirely when there's nothing to show. --}}
            @if($row['leadProperties']->isNotEmpty())
            <div class="px-5 py-2 flex items-center gap-1.5 flex-wrap" style="border-bottom:1px solid var(--border); background:var(--surface);">
                <span class="text-xs" style="color:var(--text-muted);">🏠 Enquired on:</span>
                @foreach($row['leadProperties']->take(4) as $prop)
                <span class="text-xs px-2 py-0.5 rounded-md font-medium" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                      title="{{ $prop->title }}{{ $prop->price ? ' — R' . number_format($prop->price) : '' }}">
                    {{ $prop->suburb ?: $prop->title }}
                </span>
                @endforeach
                @if($row['leadProperties']->count() > 4)
                <span class="text-xs" style="color:var(--text-muted);">+{{ $row['leadProperties']->count() - 4 }} more</span>
                @endif
            </div>
            @endif

            {{-- Match rows --}}
            <div>
                @foreach($matches as $match)
                <div class="flex items-center gap-4 px-5 py-3.5 flex-wrap"
                     style="{{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">

                    {{-- Type pill — suppressed when the whole board is
                         already locked to one listing type (an always-lit
                         badge carries no information). --}}
                    @if(!$isRentalEntry && ($listingType ?? '') === '')
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-md flex-shrink-0 whitespace-nowrap"
                          style="{{ $match->listing_type === 'rental'
                              ? 'background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);'
                              : 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 22%, transparent);' }}">
                        {{ $match->listingTypeLabel() }}
                    </span>
                    @endif

                    <span class="text-xs flex-shrink-0 whitespace-nowrap" style="color:var(--text-muted);" title="Saved">
                        {{ $match->created_at?->format('d M Y, H:i') ?? '—' }}
                    </span>

                    {{-- Assigned to — only shown when scope varies row to
                         row (Branch/Agency). On "Mine" it's always the
                         viewer, an always-lit badge with zero information,
                         so it's omitted entirely there. --}}
                    @if($scope !== 'own' && $hasAgentColumn && $match->agent_id)
                    <span class="text-xs px-2 py-0.5 rounded-md font-medium flex-shrink-0 whitespace-nowrap"
                          style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                          title="Assigned to">
                        👤 {{ $assignedAgentNames->get($match->agent_id, 'Unknown') }}
                    </span>
                    @endif

                    {{-- "Reassigned from X to Y" — ONLY when a real reassignment
                         record exists for this search (a manager moved the buyer
                         by hand). Never inferred from a portal lead, from who owns
                         or created the search, or from which agents the buyer
                         enquired with (Johan, 2026-10-07). --}}
                    @php $reassignment = $reassignmentByMatch->get($match->id); @endphp
                    @if($reassignment)
                    <span class="text-xs px-2 py-0.5 rounded-md font-medium flex-shrink-0 whitespace-nowrap"
                          style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 22%, transparent);">
                        Reassigned
                        @if($reassignment->from_agent_id) from {{ $flagAgentNames->get($reassignment->from_agent_id, 'Unknown') }}@endif
                        to {{ $flagAgentNames->get($reassignment->to_agent_id, 'Unknown') }}
                        ({{ optional($reassignment->created_at)->format('d M Y') }})
                    </span>
                    @endif

                    {{-- Working window remaining — cc4's clock, once it exists.
                         A REMAINING count, not the static setting value: the
                         clock started when the lead was first received (or
                         the match was created, if there's no portal lead
                         behind it). Lapsed renders as a distinct warning
                         state, never a negative number. --}}
                    @if($hasWorkingWindowSetting && $workingWindowDays && $match->status === 'active' && isset($match->workingWindowRemainingDays))
                        @if($match->workingWindowRemainingDays > 0)
                        <span class="text-xs px-2 py-0.5 rounded-md font-medium flex-shrink-0 whitespace-nowrap"
                              style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                              title="{{ $workingWindowDays }}-day working window">
                            {{ $match->workingWindowRemainingDays }}d left
                        </span>
                        @else
                        <span class="text-xs px-2 py-0.5 rounded-md font-medium flex-shrink-0 whitespace-nowrap"
                              style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 22%, transparent);"
                              title="{{ $workingWindowDays }}-day working window">
                            Window lapsed
                        </span>
                        @endif
                    @endif

                    {{-- Criteria --}}
                    <div class="flex items-center gap-1.5 flex-wrap flex-1 min-w-0">
                        @if($match->price_min || $match->price_max)
                        <span class="text-xs font-bold" style="color:var(--text-primary);">{{ $match->priceRangeLabel() }}</span>
                        <span class="text-xs" style="color:var(--text-muted);">·</span>
                        @endif
                        @if(!empty($match->suburbList()))
                        <span class="text-xs font-medium" style="color:var(--text-secondary);">📍 {{ implode(', ', $match->suburbList()) }}</span>
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
                        @if(!$match->category && !$match->property_type && empty($match->suburbList()) && !$match->price_min && !$match->price_max && !$match->beds_min && !$match->baths_min)
                        <span class="text-xs italic" style="color:var(--text-muted);">Any property</span>
                        @endif
                    </div>

                    {{-- Match counts --}}
                    @php $counts = $matchCounts[$match->id] ?? ['total' => 0, 'visible' => 0, 'hidden' => 0]; @endphp
                    <div class="flex items-center gap-1.5 flex-shrink-0" @if($isFirstCard && $loop->first) data-tour="re-core-matches-counts" @endif>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                              style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                              title="Total properties matching this search">
                            {{ number_format($counts['total']) }} {{ Str::plural('match', $counts['total']) }}
                        </span>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                              style="background:color-mix(in srgb, var(--ds-green, #059669) 12%, transparent); color:var(--ds-green, #059669); border:1px solid color-mix(in srgb, var(--ds-green, #059669) 25%, transparent);"
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

                    {{-- Share status — Johan's rule: no badge lit on every row.
                         Never-shared gets its own honest fact-badge (no count —
                         a baseline-less "new" tells the agent nothing). Shared
                         with nothing unseen is silence — the absence IS the
                         signal, same convention this board already uses for the
                         hidden-count and reassigned badges. Shared WITH unseen
                         matches is the one state that earns the actionable
                         badge — the whole point of the feature: scan the board,
                         see instantly who's worth sending to again. --}}
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        @if(!$match->lastSharedAt)
                        <span class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap"
                              style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
                            Never shared
                        </span>
                        @else
                        <span class="text-xs whitespace-nowrap" style="color:var(--text-muted);"
                              title="Last shared {{ $match->lastSharedAt->format('d M Y, H:i') }}">
                            Shared {{ $match->lastSharedAt->diffForHumans() }}
                        </span>
                        @if($match->neverSharedCount > 0)
                        <button type="button" x-data
                                @click="$dispatch('open-new-since-share', { url: '{{ route('corex.core-matches.new-since-share', $match) }}' })"
                                class="text-xs px-2 py-0.5 rounded-md font-semibold whitespace-nowrap border-0 cursor-pointer"
                                style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 14%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 28%, transparent);">
                            Send {{ $match->neverSharedCount }} new
                        </button>
                        @endif
                        @endif
                    </div>

                    {{-- Action --}}
                    @if(auth()->user()->hasPermission('access_core_matches'))
                    <a href="{{ route('corex.contacts.matches.edit', [$contact, $match]) }}"
                       class="corex-btn-outline text-xs flex-shrink-0 whitespace-nowrap inline-flex items-center gap-1.5"
                       title="Edit this wishlist / match criteria">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                        Edit
                    </a>
                    @endif
                    <a href="{{ route('corex.contacts.matches.results', [$contact, $match]) }}"
                       @if($isFirstCard && $loop->first) data-tour="re-core-matches-view" @endif
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

    <div>{{ $contacts->links() }}</div>
    @endif

    {{-- Notes popup — Johan: "the notes should open in a popup not
         redirect to the contact", so a manager can read them without
         losing the board and its filters. Read-only (see
         ContactNoteController::quickView()); one modal shell for the whole
         page, content fetched on demand for whichever contact was
         clicked — never pre-loaded for every row. --}}
    <div x-data="{ loading: false, html: '' }"
         @open-notes-quick-view.window="
             loading = true; html = '';
             $dispatch('open-modal', 'notes-quick-view');
             fetch($event.detail.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                 .then(r => r.text())
                 .then(t => { html = t; loading = false; })
                 .catch(() => { html = '<p class=&quot;text-sm&quot; style=&quot;color:var(--ds-crimson);&quot;>Could not load notes.</p>'; loading = false; })
         ">
        <x-modal name="notes-quick-view" max-width="lg">
            <div class="p-6">
                <div x-show="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
                <div x-show="!loading" x-html="html"></div>
                <div class="mt-5 text-right">
                    <button type="button" class="corex-btn-outline text-xs" @click="$dispatch('close-modal', 'notes-quick-view')">Close</button>
                </div>
            </div>
        </x-modal>
    </div>

    {{-- Add-note popup — ONE modal for the whole board; the row's "+ Note" button
         supplies the contact's name and the contact-notes store URL. "Note only"
         saves the note; "Contacted and note" saves it AND marks the buyer contacted
         (same endpoint, same contacted rule as the contact screen). Returns to this
         board with its filters intact. --}}
    @if(count($noteableContactIds))
    <div x-data="{ action: '', name: '' }"
         @open-core-match-note.window="action = $event.detail.action; name = $event.detail.name; $dispatch('open-modal', 'core-match-note')">
        <x-modal name="core-match-note" max-width="md">
            <form method="POST" :action="action" class="p-5 space-y-3">
                @csrf
                <input type="hidden" name="redirect_to" value="back">
                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="name"></div>
                <textarea name="body" rows="3" required maxlength="5000" placeholder="Note"
                          class="w-full rounded-md px-3 py-2 text-sm resize-none"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                <div class="flex justify-end items-center gap-2">
                    <button type="button" class="text-xs" style="color:var(--text-muted);" @click="$dispatch('close-modal', 'core-match-note')">Cancel</button>
                    <button type="submit" class="corex-btn-outline text-sm">Note only</button>
                    <button type="submit" name="mark_contacted" value="1" class="corex-btn-primary text-sm">Contacted and note</button>
                </div>
            </form>
        </x-modal>
    </div>
    @endif

    {{-- Update buyer pipeline popup — ONE for the whole board; the row's button supplies the
         buyer's name, current status and the pipeline endpoints. The list is the Buyer Pipeline
         board's own (New / Warm / Cold / Lost). New / Warm / Cold post to the board's own state
         endpoint (BuyerPipelineController::updateState) exactly as a drag on the board does, then
         the board reloads: the chip shows the new status, and a buyer whose new status this agency
         excludes from Core Matches (CoreMatchBuyerGate) drops off the list. Lost does NOT post
         here — it opens the shared Mark-Lost dialog (the same partial the buyer page uses), which
         captures the reason and posts to the board's own mark-lost endpoint. --}}
    @if(count($pipelineMovableContactIds))
    <div x-data="{
            name: '', current: '', state: '', noun: 'buyer', stateUrl: '', lostUrl: '', busy: false,
            open(d) { this.name = d.name; this.current = d.state; this.state = d.state; this.noun = d.noun; this.stateUrl = d.stateUrl; this.lostUrl = d.lostUrl; this.$dispatch('open-modal', 'core-match-pipeline'); },
            async save() {
                if (!this.state || this.state === this.current || this.busy) return;
                if (this.state === 'lost') {
                    this.$dispatch('close-modal', 'core-match-pipeline');
                    const dlg = this.$refs.coreMatchLostModal;
                    const form = dlg.querySelector('[data-mark-lost-form]');
                    form.action = this.lostUrl;
                    dlg.querySelectorAll('[data-lost-noun]').forEach(el => el.textContent = this.noun);
                    dlg.showModal();
                    return;
                }
                this.busy = true;
                try {
                    const r = await fetch(this.stateUrl, {
                        method: 'PATCH',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: JSON.stringify({ state: this.state }),
                    });
                    if (r.ok) { window.location.reload(); return; }
                    (window.showToast || alert)(r.status === 404
                        ? 'This ' + this.noun + ' is no longer on the pipeline — someone may have archived or moved them. Refresh to see the current list.'
                        : 'Could not update the ' + this.noun + ' pipeline status.', 'error');
                } catch (e) {
                    (window.showToast || alert)('Network error.', 'error');
                }
                this.busy = false;
            },
         }"
         @open-core-match-pipeline.window="open($event.detail)">
        <x-modal name="core-match-pipeline" max-width="md">
            <div class="p-5 space-y-3">
                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="name"></div>
                <label class="block text-xs font-medium" style="color:var(--text-secondary);">
                    Buyer pipeline status
                    <select x-model="state" class="mt-1 w-full rounded-md px-3 py-2 text-sm"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        @foreach(['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost'] as $stateKey => $stateLabel)
                        <option value="{{ $stateKey }}">{{ $stateLabel }}</option>
                        @endforeach
                        <option value="won" disabled x-show="current === 'won'">Won</option>
                    </select>
                </label>
                <p class="text-xs" style="color:var(--text-muted);" x-show="state === 'lost' && current !== 'lost'">You will be asked for the reason next.</p>
                <div class="flex justify-end items-center gap-2">
                    <button type="button" class="text-xs" style="color:var(--text-muted);" @click="$dispatch('close-modal', 'core-match-pipeline')">Cancel</button>
                    <button type="button" class="corex-btn-primary text-sm" :disabled="!state || state === current || busy" @click="save()"
                            x-text="state === 'lost' ? 'Continue' : 'Update'">Update</button>
                </div>
            </div>
        </x-modal>

        {{-- The shared Mark-Lost dialog (one copy, also used by the buyer page). The opener above
             points its form at the chosen buyer's mark-lost endpoint; markLost() returns here. --}}
        @include('command-center.buyers._mark-lost-dialog', ['ref' => 'coreMatchLostModal', 'agencyId' => (int) auth()->user()->effectiveAgencyId(), 'noun' => 'buyer', 'action' => null])
    </div>
    @endif

    @if(count($reassignableContactIds))
        @include('corex.core-matches._move-buyer-modal', ['moveBuyerAgents' => $moveBuyerAgents])
    @endif

    {{-- "Send N new" popup — same shell/pattern as the notes popup above
         (one modal, content fetched on demand for whichever row's badge was
         clicked), not a second convention. --}}
    <div x-data="{ loading: false, html: '' }"
         @open-new-since-share.window="
             loading = true; html = '';
             $dispatch('open-modal', 'new-since-share');
             fetch($event.detail.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                 .then(r => r.text())
                 .then(t => { html = t; loading = false; })
                 .catch(() => { html = '<p class=&quot;text-sm&quot; style=&quot;color:var(--ds-crimson);&quot;>Could not load.</p>'; loading = false; })
         ">
        <x-modal name="new-since-share" max-width="lg">
            <div class="p-6">
                <div x-show="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
                <div x-show="!loading" x-html="html"></div>
                <div class="mt-5 text-right">
                    <button type="button" class="corex-btn-outline text-xs" @click="$dispatch('close-modal', 'new-since-share')">Close</button>
                </div>
            </div>
        </x-modal>
    </div>

</div>
@endsection
