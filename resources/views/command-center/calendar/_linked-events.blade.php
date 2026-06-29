{{--
    Contact linked-appointments section (AT-114 Part A).

    Shows EVERY calendar event linked to this contact — property OPTIONAL — split
    into Upcoming (event_date >= now, soonest first) and Past (event_date < now,
    recent first), so meetings / calls / general appointments appear, not just
    property viewings. Identical on the contact record and the buyer pipeline
    detail (both include this partial).

    Each PAST event with no captured feedback gets a "Provide feedback" action
    that summons the shared reusable feedback modal in place
    ([[at114-feedback-from-anywhere]] — _event-feedback-modal.blade.php) and posts
    to the canonical command-center.calendar.feedback.store path. After save the
    modal fires 'corex:feedback-saved' and the badge flips here without a reload.

    Requires: $contact (contact record passes $contact; buyer detail passes $buyer).

    Scoping mirrors the existing contact-viewings reads: resolve the contact's own
    calendar_event_links, then load those events withoutGlobalScopes (the contact
    is already agency-scoped, so its linked events are too; this matches the
    deliberate CAL-7 read pattern that surfaces legacy/role-NULL links). Dismissed
    events are excluded. Permission gating is a separate concern (not here).
--}}
@php
    $cid = $contact->id;
    $linkedEventIds = \DB::table('calendar_event_links')
        ->where('linkable_type', \App\Models\Contact::class)
        ->where('linkable_id', $cid)
        ->pluck('calendar_event_id')->unique()->values();

    $linkedEvents = collect();
    if ($linkedEventIds->isNotEmpty()) {
        $evs = \App\Models\CommandCenter\CalendarEvent::withoutGlobalScopes()
            ->whereIn('id', $linkedEventIds)
            ->where('status', '!=', 'dismissed')
            ->with('linkedProperties')
            ->orderBy('event_date')
            ->get();

        $agentNames = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $evs->pluck('user_id')->unique()->filter())
            ->pluck('name', 'id');

        // Feedback already captured for THIS contact on these events.
        $fedEventIds = \DB::table('calendar_event_feedback')
            ->where('contact_id', $cid)
            ->whereIn('calendar_event_id', $linkedEventIds)
            ->pluck('calendar_event_id')->unique()->flip();

        $linkedEvents = $evs->map(function ($ev) use ($agentNames, $fedEventIds) {
            $prop = $ev->linkedProperties->first();
            return [
                'id'          => $ev->id,
                'title'       => $ev->title,
                'event_date'  => $ev->event_date,
                'type_label'  => $ev->category ?: ucfirst((string) $ev->event_type),
                'status'      => $ev->status,
                'agent_name'  => $agentNames->get($ev->user_id, 'Unassigned'),
                'property_id' => $prop?->id,
                'property_address' => $prop
                    ? (method_exists($prop, 'buildDisplayAddress') ? $prop->buildDisplayAddress() : ($prop->title ?? "Property #{$prop->id}"))
                    : null,
                'has_feedback' => $fedEventIds->has($ev->id),
            ];
        });
    }

    $now = now();
    $upcoming = $linkedEvents->filter(fn ($e) => \Carbon\Carbon::parse($e['event_date'])->gte($now))
        ->sortBy('event_date')->values();
    $past = $linkedEvents->filter(fn ($e) => \Carbon\Carbon::parse($e['event_date'])->lt($now))
        ->sortByDesc('event_date')->values();
@endphp

{{-- feedbackDone tracks events whose badge flipped in-place this session. --}}
<div x-data="{ feedbackDone: [] }"
     x-on:corex:feedback-saved.window="if (!feedbackDone.includes($event.detail.eventId)) feedbackDone.push($event.detail.eventId)"
     class="space-y-6">

    {{-- Upcoming appointments --}}
    <div>
        <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Upcoming Appointments ({{ number_format($upcoming->count()) }})</h3>
        @forelse($upcoming as $e)
            <div class="rounded-md p-4 mb-2" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        @if($e['property_id'])
                            <a href="{{ route('corex.properties.show', $e['property_id']) }}" target="_blank"
                               class="text-sm font-semibold truncate block no-underline hover:underline" style="color:var(--text-primary);">{{ $e['title'] }}</a>
                            <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">{{ $e['property_address'] }}</div>
                        @else
                            <span class="text-sm font-semibold truncate block" style="color:var(--text-primary);">{{ $e['title'] }}</span>
                            <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">No property linked</div>
                        @endif
                    </div>
                    <div class="text-right flex-shrink-0">
                        <div class="text-[10px]" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($e['event_date'])->format('D, j M Y H:i') }}</div>
                        <div class="text-[10px]" style="color:var(--text-muted);">Agent: {{ $e['agent_name'] }}</div>
                        <span class="ds-badge ds-badge-info mt-0.5">{{ ucfirst($e['type_label']) }}</span>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-xs py-3" style="color:var(--text-muted);">None</p>
        @endforelse
    </div>

    {{-- Past appointments --}}
    <div>
        <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Past Appointments ({{ number_format($past->count()) }})</h3>
        @forelse($past as $e)
            <div class="rounded-md p-4 mb-2" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        @if($e['property_id'])
                            <a href="{{ route('corex.properties.show', $e['property_id']) }}" target="_blank"
                               class="text-sm font-semibold truncate block no-underline hover:underline" style="color:var(--text-primary);">{{ $e['title'] }}</a>
                            <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">{{ $e['property_address'] }}</div>
                        @else
                            <span class="text-sm font-semibold truncate block" style="color:var(--text-primary);">{{ $e['title'] }}</span>
                            <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">No property linked</div>
                        @endif
                    </div>
                    <div class="text-right flex-shrink-0">
                        <div class="text-[10px]" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($e['event_date'])->format('D, j M Y H:i') }}</div>
                        <div class="text-[10px]" style="color:var(--text-muted);">Agent: {{ $e['agent_name'] }}</div>
                        <span class="ds-badge ds-badge-default mt-0.5">{{ ucfirst($e['type_label']) }}</span>
                    </div>
                </div>

                {{-- Feedback state + in-place "Provide feedback" trigger --}}
                <div class="mt-2">
                    @if($e['has_feedback'])
                        <span class="ds-badge ds-badge-success">Feedback captured</span>
                    @else
                        {{-- flips to a confirmation chip in place once saved this session --}}
                        <template x-if="feedbackDone.includes({{ $e['id'] }})">
                            <span class="ds-badge ds-badge-success">Feedback captured</span>
                        </template>
                        <template x-if="!feedbackDone.includes({{ $e['id'] }})">
                            <div class="flex items-center gap-2">
                                <span class="ds-badge ds-badge-default">No feedback</span>
                                <button type="button"
                                        @click="window.dispatchEvent(new CustomEvent('corex:open-event-feedback', { detail: { eventId: {{ $e['id'] }} } }))"
                                        class="text-[11px] font-semibold no-underline hover:underline" style="color:var(--brand-icon, #00d4aa);">
                                    Provide feedback
                                </button>
                            </div>
                        </template>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-xs py-3" style="color:var(--text-muted);">None</p>
        @endforelse
    </div>

    {{-- The reusable feedback modal (rendered @once per page). --}}
    @include('command-center.calendar._event-feedback-modal')
</div>
