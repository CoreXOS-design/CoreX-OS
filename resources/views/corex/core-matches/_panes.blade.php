{{-- Core Matches board — the searches pane blocks, one per contact in $rows.
     Included by index.blade.php (first page) and _page.blade.php (each batch the
     list loads on scroll). Expects $rows, $scope, $showContactAgent, $withTour
     (true only on the first page) plus the controller's board variables. --}}
@php
    $stateDot = fn (?string $state) => match ($state) {
        'new'  => 'var(--brand-icon, #0ea5e9)',
        'warm' => 'var(--ds-green, #059669)',
        'cold' => 'var(--ds-amber, #f59e0b)',
        'lost' => 'var(--ds-crimson, #c41e3a)',
        'won'  => 'var(--ds-green, #059669)',
        default => null,
    };
    $attnStyle = 'color:var(--ds-amber, #f59e0b);';
    $ringStyle = 'display:inline-block; width:7px; height:7px; border-radius:50%; border:1.5px solid var(--ds-amber, #f59e0b); margin-right:5px; vertical-align:middle;';
    $labelStyle = 'display:block; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted);';
@endphp
@foreach($rows as $row)
@php
    $contact = $row['contact'];
    $matches = $row['matches'];
    $isFirstCard = $withTour && $loop->first;
    $dot = $stateDot($contact->buyer_state);
    $noun = $matches->contains(fn ($m) => $m->listing_type !== 'rental') ? 'buyer' : 'tenant';
@endphp
<div x-show="sel === {{ (int) $contact->id }}" data-cm-pane="{{ (int) $contact->id }}" @if(!$withTour || !$loop->first) x-cloak @endif class="space-y-3">

    {{-- Contact header — identity + facts that belong to the CONTACT,
         never repeated once per search below it. --}}
    <div class="rounded-md px-4 py-3.5 flex items-center gap-3 flex-wrap" style="background:var(--surface); border:1px solid var(--border);">
        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0"
             style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">{{ $contact->initials }}</div>
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
                   class="text-sm font-semibold no-underline leading-tight" style="color:var(--text-primary);">{{ $contact->full_name }}</a>
                <span class="text-xs" style="color:var(--text-secondary);">
                    @if($dot)<span title="Buyer pipeline status" data-buyer-state="{{ $contact->buyer_state }}"><span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:{{ $dot }}; margin-right:5px; vertical-align:middle;"></span>{{ ucfirst($contact->buyer_state) }}</span> · @endif
                    @if($contact->type){{ $contact->type->name }} · @endif
                    {{-- Last contact — ONE value, relative, absolute date on hover. --}}
                    @if($contact->last_contacted_at)
                    <span title="Last contacted {{ $contact->last_contacted_at->format('d M Y, H:i') }}">Contacted {{ $contact->last_contacted_at->diffForHumans() }}</span>
                    @else
                    <span class="font-medium" style="{{ $attnStyle }}"><span style="{{ $ringStyle }}"></span>Never contacted</span>
                    @endif
                </span>
            </div>
            <div class="text-xs mt-0.5 flex items-center gap-x-1.5 flex-wrap" style="color:var(--text-secondary);">
                @if($contact->phone)<span>{{ $contact->phone }}</span>@endif
                @if($contact->phone && $contact->email)<span style="color:var(--text-muted);">·</span>@endif
                @if($contact->email)<span>{{ $contact->email }}</span>@endif
                {{-- Properties the lead(s) came in on — a fact about the buyer's
                     enquiry history, shown once per contact, absent when empty. --}}
                @if($row['leadProperties']->isNotEmpty())
                @if($contact->phone || $contact->email)<span style="color:var(--text-muted);">·</span>@endif
                <span>Enquired on
                    @foreach($row['leadProperties']->take(4) as $prop)<span title="{{ $prop->title }}{{ $prop->price_on_application ? ' — Price on Application' : ($prop->price ? ' — R' . number_format($prop->price) : '') }}">{{ $prop->suburb ?: $prop->title }}</span>@if(!$loop->last), @endif @endforeach
                    @if($row['leadProperties']->count() > 4)<span style="color:var(--text-muted);">+{{ $row['leadProperties']->count() - 4 }} more</span>@endif
                </span>
                @endif
                {{-- Ruling A (Johan, 2026-10-07): the first agent to receive this buyer's
                     lead is their primary agent; leads they also sent to other agents are
                     information only — never "moved" or "reassigned". --}}
                @if($alsoEnquiredByContact->has($contact->id))
                <span style="color:var(--text-muted);">·</span>
                <span style="color:var(--text-muted);" title="This buyer also sent enquiries to these agents' listings. Their primary agent has not changed.">
                    Also enquired with {{ $alsoEnquiredByContact->get($contact->id)->implode(', ') }}
                </span>
                @endif
            </div>
        </div>
        @if($showContactAgent)
        <div class="flex flex-col items-end flex-shrink-0 pr-3 leading-tight" style="border-right:1px solid var(--border);">
            <span class="text-[9px] font-semibold uppercase" style="letter-spacing:.06em; color:var(--text-muted);">Agent</span>
            <span class="text-xs font-semibold whitespace-nowrap" style="color:var(--text-primary);">{{ optional($contact->agent)->name ?? 'Unassigned' }}</span>
        </div>
        @endif
        <div class="flex items-center gap-1.5 flex-wrap flex-shrink-0">
            {{-- Notes — read in a popup (Johan) so the board and its filters stay put;
                 fetched on demand, absent entirely when there are none. --}}
            @if($contact->contact_notes_count > 0 && in_array((int) $contact->id, $buyerNotesViewableIds ?? [], true))
            <button type="button" x-data
               @click="$dispatch('open-notes-quick-view', { url: '{{ route('corex.buyer-notes.show', $contact->id) }}' })"
               class="corex-btn-outline text-xs cursor-pointer" title="Read the notes on this contact">
                {{ $contact->contact_notes_count }} {{ Str::plural('note', $contact->contact_notes_count) }}
            </button>
            @endif
            {{-- Add a note / mark contacted — the SAME contact-notes endpoint the
                 contact screen posts to (ContactNoteController::store). --}}
            @if(in_array($contact->id, $noteableContactIds, true))
            <button type="button" x-data
               @click="$dispatch('open-core-match-note', { action: '{{ route('corex.contacts.notes.store', $contact) }}', name: @js($contact->full_name) })"
               class="corex-btn-outline text-xs cursor-pointer" title="Add a note">+ Note</button>
            @endif
            {{-- Update buyer pipeline — moves the buyer on the Buyer Pipeline board itself
                 (same endpoints, same statuses, same Lost-reason dialog). --}}
            @if(feature('buyer-pipeline') && in_array($contact->id, $pipelineMovableContactIds, true))
            <button type="button" x-data
               @click="$dispatch('open-core-match-pipeline', {
                   name: @js($contact->full_name),
                   state: @js($contact->buyer_state),
                   noun: @js($noun),
                   stateUrl: @js(route('command-center.buyers.update-state', $contact)),
                   lostUrl: @js(route('command-center.buyers.mark-lost', $contact)),
               })"
               class="corex-btn-outline text-xs cursor-pointer"
               title="Move this {{ $noun }} to a different Buyer Pipeline status">Update buyer pipeline</button>
            @endif
            {{-- Move buyer to another agent — managers only (core_matches.reassign). --}}
            @if(in_array($contact->id, $reassignableContactIds, true))
            <button type="button" x-data
               @click="$dispatch('open-move-buyer', {
                   name: @js($contact->full_name),
                   current: @js(optional($moveBuyerAgents->firstWhere('id', $contact->agent_id))->name),
                   action: @js(route('corex.core-matches.reassign-buyer', $contact)),
                   currentId: {{ (int) $contact->agent_id }},
               })"
               class="corex-btn-outline text-xs cursor-pointer"
               title="Move this buyer, and all their saved searches, to another agent">Move buyer</button>
            @endif
        </div>
    </div>

    {{-- One card per saved search --}}
    @foreach($matches as $match)
    @php
        $counts = $matchCounts[$match->id] ?? ['total' => 0, 'visible' => 0, 'hidden' => 0];
        $reassignment = $reassignmentByMatch->get($match->id);
        $showType = !$isRentalEntry && ($listingType ?? '') === '';
        $sizeBits = [];
        foreach ([[$match->beds_min, 'beds', 'bed'], [$match->baths_min, 'baths', 'bath'], [$match->garages_min, 'garages', 'garage']] as [$val, $plural, $single]) {
            if ($val !== null) { $sizeBits[] = $val . '+ ' . ((int) $val === 1 ? $single : $plural); }
        }
        $typeBits = array_values(array_filter([$match->category, $match->property_type]));
        $isBlank = !$typeBits && empty($match->suburbList()) && !$match->price_min && !$match->price_max && !$sizeBits;
    @endphp
    <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex items-center gap-3 flex-wrap px-4 py-2.5" style="border-bottom:1px solid var(--border);">
            {{-- Type label — suppressed when the whole board is already locked to
                 one listing type (an always-lit label carries no information). --}}
            @if($showType)
            <span class="text-[13px] font-bold" style="color:var(--text-primary);">{{ $match->listingTypeLabel() }}</span>
            @endif
            <span class="text-xs" style="color:var(--text-secondary);" title="Saved">
                Saved {{ $match->created_at?->format('d M Y, H:i') ?? '—' }}
                {{-- Assigned to — only shown when scope varies row to row (Branch/Agency). --}}
                @if($scope !== 'own' && $hasAgentColumn && $match->agent_id) · {{ $assignedAgentNames->get($match->agent_id, 'Unknown') }}@endif
            </span>
            {{-- "Reassigned from X to Y" — ONLY when a real reassignment record exists
                 (a manager moved the buyer by hand). Never inferred (Johan, 2026-10-07). --}}
            @if($reassignment)
            <span class="text-xs" style="color:var(--text-secondary);">
                Reassigned
                @if($reassignment->from_agent_id) from {{ $flagAgentNames->get($reassignment->from_agent_id, 'Unknown') }}@endif
                to {{ $flagAgentNames->get($reassignment->to_agent_id, 'Unknown') }}
                ({{ optional($reassignment->created_at)->format('d M Y') }})
            </span>
            @endif
            {{-- Working window remaining — a REMAINING count from when the lead was
                 first received; lapsed is a distinct state, never a negative number. --}}
            @if($hasWorkingWindowSetting && $workingWindowDays && $match->status === 'active' && isset($match->workingWindowRemainingDays))
                @if($match->workingWindowRemainingDays > 0)
                <span class="text-xs" style="color:var(--text-secondary);" title="{{ $workingWindowDays }}-day working window">{{ $match->workingWindowRemainingDays }} days left</span>
                @else
                <span class="text-xs font-medium" style="{{ $attnStyle }}" title="{{ $workingWindowDays }}-day working window"><span style="{{ $ringStyle }}"></span>Window lapsed</span>
                @endif
            @endif
            <div class="flex items-center gap-1.5 ml-auto flex-shrink-0">
                @if(auth()->user()->hasPermission('access_core_matches'))
                <a href="{{ route('corex.contacts.matches.edit', [$contact, $match]) }}"
                   class="corex-btn-outline text-xs whitespace-nowrap inline-flex items-center gap-1.5"
                   title="Edit this wishlist / match criteria">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                    Edit
                </a>
                @endif
                <a href="{{ route('corex.contacts.matches.results', [$contact, $match]) }}"
                   @if($isFirstCard && $loop->first) data-tour="re-core-matches-view" @endif
                   class="corex-btn-primary text-xs whitespace-nowrap inline-flex items-center gap-1.5">
                    View Matches
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>
        </div>

        {{-- Criteria — four labelled slots --}}
        @if($isBlank)
        <div class="px-4 py-3 text-xs italic" style="color:var(--text-muted);">Any property</div>
        @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 px-4 py-3">
            <div><span style="{{ $labelStyle }}">Price</span><span class="text-xs font-semibold" style="color:var(--text-primary);">{{ ($match->price_min || $match->price_max) ? $match->priceRangeLabel() : 'Any' }}</span></div>
            <div><span style="{{ $labelStyle }}">Areas</span><span class="text-xs font-semibold" style="color:var(--text-primary);">{{ !empty($match->suburbList()) ? implode(', ', $match->suburbList()) : 'Any' }}</span></div>
            <div><span style="{{ $labelStyle }}">Type</span><span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $typeBits ? implode(' · ', $typeBits) : 'Any' }}</span></div>
            <div><span style="{{ $labelStyle }}">Size</span><span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $sizeBits ? implode(' · ', $sizeBits) : 'Any' }}</span></div>
        </div>
        @endif

        {{-- Counts + share status --}}
        <div class="flex items-center gap-4 flex-wrap px-4 py-2.5 rounded-b-md" style="background:var(--surface-2); border-top:1px solid var(--border);">
            <div class="flex items-center gap-3 text-xs" style="color:var(--text-secondary); font-variant-numeric: tabular-nums;" @if($isFirstCard && $loop->first) data-tour="re-core-matches-counts" @endif>
                <span title="Total properties matching this search"><b style="color:var(--text-primary);">{{ number_format($counts['total']) }}</b> {{ Str::plural('match', $counts['total']) }}</span>
                <span title="Visible to the client"><b style="color:var(--text-primary);">{{ number_format($counts['visible']) }}</b> visible</span>
                @if($counts['hidden'] > 0)
                <span title="Hidden from this match"><b style="color:var(--text-primary);">{{ number_format($counts['hidden']) }}</b> hidden</span>
                @endif
            </div>
            {{-- Share status — Johan's rule: no badge lit on every row. Never-shared is
                 an honest fact; shared with nothing unseen is silence; shared WITH unseen
                 matches earns the one action: Send N new. --}}
            <x-core-match-share-status class="ml-auto" :match-id="$match->id"
                :status="['match_id' => $match->id, 'never_sent_count' => (int) $match->neverSharedCount, 'last_shared_at' => $match->lastSharedAt?->toIso8601String(), 'last_shared_human' => $match->lastSharedAt?->diffForHumans()]"
                :quick-view-url="route('corex.core-matches.new-since-share', $match)"
                :never-style="$attnStyle" :ring-style="$ringStyle" />
        </div>
    </div>
    @endforeach
</div>
@endforeach
