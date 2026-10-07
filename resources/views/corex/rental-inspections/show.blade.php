@extends('layouts.corex')

{{--
    .ai/specs/rental-inspections.md — the inspection detail screen: a read
    view of what was recorded, plus administrative lifecycle (cancel /
    archive / restore). Recording new observations/photos/signatures still
    happens on the property's Rental Images tab (§1/§4).
--}}

@php
    $statusBadgeClass = match ($inspection->status) {
        'completed' => 'ds-badge-success',
        'cancelled' => 'ds-badge-danger',
        'awaiting_signature', 'in_progress' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
    $conditionBadgeClass = fn ($condition) => $condition === 'good' ? 'ds-badge-success' : 'ds-badge-danger';
@endphp

@section('content')
{{-- AT-439 Part 3 — full-width (was max-w-3xl), matching the Lease Hub/Work
     Order show precedent: every line of space is data the agent needs or a
     control they act on. Two-column below the header: record + actions
     left, scans/signatures (the paperwork evidence trail) right. --}}
<div class="p-6 max-w-7xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber);">{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">{{ $errors->first() }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $inspection->property?->trashed() ? ' (archived)' : '' }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</span>
            <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', '-', $inspection->type)) }} inspection</span>
        </div>
        <div class="flex items-center gap-2">
            {{-- The printable tick-box form — takes it to the property in wet
                 ink, scans it back in (OMR reader lane builds on this). --}}
            <a href="{{ route('corex.rental-inspections.form', $inspection) }}" class="corex-btn-outline text-xs">Download printable form</a>
            {{-- Johan, 2026-09-23, approved — the COMPLETED report: no photos,
                 a QR/link to the public page instead. A different document
                 from the printable form above (that one is blank, for
                 capture BEFORE an inspection; this one is the record AFTER). --}}
            <a href="{{ route('corex.rental-inspections.report', $inspection) }}" class="corex-btn-outline text-xs">Download report (PDF)</a>
            {{-- Conductor brief 2026-09-29 — "print for signature": the same
                 report, plus blank signature blocks for every outstanding
                 party, to print and send/hand for a wet-ink signature. --}}
            @if(!in_array($inspection->status, ['completed', 'cancelled']))
                <a href="{{ route('corex.rental-inspections.print-for-signature', $inspection) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">Print for signature</a>
            @endif
            @if($inspection->type === 'out')
                {{-- rental-inspection-form.md §7 — the in-vs-out deposit comparison. --}}
                <a href="{{ route('corex.rental-inspections.deposit-comparison', $inspection) }}" class="corex-btn-outline text-xs">Move-out comparison</a>
            @endif
            <a href="{{ route('corex.rental-inspections.index') }}" class="corex-btn-outline text-xs">&larr; All inspections</a>
        </div>
    </div>

    <x-rental-context-bar :property="$inspection->property" :lease="$inspection->lease" current="inspections" />

    <div class="grid grid-cols-3 gap-4">
    <div class="col-span-3 lg:col-span-2 space-y-4">

    {{-- Johan's ruling, 2026-09-23 — "Next inspection" records the chain:
         In -> Routine -> Routine -> Out, any length. Hidden once a
         successor already exists (the migration's own unique index
         enforces this server-side too — one chain, never a fork), and In
         is never offered here since it can only ever be the first link
         (RentalInspection::startNext()'s own guard). --}}
    @permission('rental_inspections.create')
        @if(! $inspection->nextInChain)
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <form method="POST" action="{{ route('corex.rental-inspections.next', $inspection) }}" class="flex items-center gap-2 flex-wrap">
                    @csrf
                    <span class="text-xs font-semibold" style="color: var(--text-secondary);">Next inspection:</span>
                    <select name="type" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                        <option value="ad_hoc">Routine (mid-tenancy)</option>
                        <option value="out">Out</option>
                    </select>
                    <button type="submit" class="corex-btn-primary text-xs">Start</button>
                    <span class="text-xs" style="color: var(--text-muted);">Compares against this inspection's own recorded condition, room by room.</span>
                </form>
            </div>
        @else
            <p class="text-xs" style="color: var(--text-muted);">
                Next in this chain:
                <a href="{{ route('corex.rental-inspections.show', $inspection->nextInChain) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">
                    {{ ucfirst(str_replace('_', '-', $inspection->nextInChain->type)) }}-inspection
                </a>
            </p>
        @endif
    @endpermission
    @if($inspection->previousInspection)
        <p class="text-xs" style="color: var(--text-muted);">
            Follows:
            <a href="{{ route('corex.rental-inspections.show', $inspection->previousInspection) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">
                {{ ucfirst(str_replace('_', '-', $inspection->previousInspection->type)) }}-inspection
            </a>
        </p>
    @endif

    {{-- Johan, 2026-09-23, approved — the public link a tenant/landlord
         with no CoreX login uses (also what the PDF's QR/link points at).
         Regenerating shows a NEW link and immediately invalidates any
         previous one — RentalInspection::generatePublicLink()'s own
         docblock. --}}
    @permission('rental_inspections.public_link')
        <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold">Public link</h2>
            @if($inspection->publicLinkIsAvailable())
                <p class="text-xs break-all" style="color: var(--text-secondary);">{{ route('rental-inspections.public.show', $inspection->public_token) }}</p>
                <p class="text-xs" style="color: var(--text-muted);">Live until {{ $inspection->public_token_expires_at->format('Y-m-d') }}.</p>
            @elseif($inspection->publicLinkIsValid())
                {{-- Token still unexpired, but the inspection is cancelled: the link is switched off (RentalInspection::findByPublicToken()). --}}
                <p class="text-xs" style="color: var(--text-muted);">The link is switched off while this inspection is cancelled.</p>
            @else
                <p class="text-xs" style="color: var(--text-muted);">No live link — generate one to share. The downloaded report only carries the QR code / link once a live link exists.</p>
            @endif
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('corex.rental-inspections.public-link.generate', $inspection) }}">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">{{ $inspection->publicLinkIsValid() ? 'Regenerate' : 'Generate' }} link</button>
                </form>
                @if($inspection->publicLinkIsValid())
                    <form method="POST" action="{{ route('corex.rental-inspections.public-link.revoke', $inspection) }}" onsubmit="return confirm('Revoke this link? Anyone with the current link or PDF will lose access immediately.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Revoke</button>
                    </form>
                @endif
            </div>
        </div>
    @endpermission

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div><span style="color: var(--text-muted);">Tenant(s):</span> {{ $inspection->lease?->tenantNames() ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Recorded by:</span> {{ $inspection->createdBy?->name ?? '—' }}</div>
            <div>
                <span style="color: var(--text-muted);">Scheduled:</span>
                {{ $inspection->scheduled_for?->format('Y-m-d') ?? '—' }}
                @if($inspection->scheduled_for && $inspection->scheduled_time)
                    at {{ substr((string) $inspection->scheduled_time, 0, 5) }}
                @endif
                @if($inspection->scheduled_duration_minutes)
                    ({{ $inspection->scheduled_duration_minutes }} min)
                @endif
            </div>
            <div><span style="color: var(--text-muted);">Inspector:</span> {{ $inspection->inspector?->name ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Completed:</span> {{ $inspection->completed_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Fault-report deadline:</span> {{ $inspection->fault_report_deadline_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Signing deadline:</span> {{ $inspection->signing_deadline_at?->format('Y-m-d H:i') ?? '—' }}</div>
            @if($inspection->schedule_note)
                <div class="col-span-2"><span style="color: var(--text-muted);">Schedule note:</span> {{ $inspection->schedule_note }}</div>
            @endif
        </div>

        @if($inspection->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $inspection->cancelled_at?->format('Y-m-d') }} by {{ $inspection->cancelledBy?->name }}: {{ $inspection->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            {{-- §45.8 (Build I-6b) — each button is gated by its OWN permission now (was one blanket `.create`). --}}
            @if(true)
                {{-- §43 — "Start" opens the SAME recording tab the original
                     immediate-Start flow always landed on; a scheduled
                     inspection that hasn't been opened yet needs an
                     explicit way in, since nothing redirected here
                     automatically the way start() does. --}}
                @if($inspection->scheduled_for && $inspection->isRecordable())
                    @permission('rental_inspections.create')
                    <a href="{{ route('corex.properties.show', ['property' => $inspection->property_id, 'tab' => 'inspections']) }}" class="corex-btn-primary text-xs">Start recording</a>
                    @endpermission
                    @permission('rental_inspections.reschedule')
                    <button type="button" onclick="document.getElementById('reschedule-inspection-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Reschedule</button>
                    @endpermission
                @endif
                @if(!in_array($inspection->status, ['completed', 'cancelled'], true))
                    @permission('rental_inspections.cancel')
                    <button type="button" onclick="document.getElementById('cancel-inspection-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel inspection</button>
                    @endpermission
                @endif
                {{-- 2026-09-20 — isDeletable()/the completed-status restriction are both
                     retired: archiving is now unconditional (real evidence is never
                     destroyed by a soft delete), matching RentalInspectionController::
                     destroy(). This button is only hidden once the record is already
                     archived, below. --}}
                {{-- §45.8 (Build I-6b) — a completed, signed inspection is evidence: archiving it needs its own
                     permission (`archive_completed`, managers). The server re-checks; this only hides a dead button. --}}
                @if(!$inspection->trashed())
                    @if(auth()->user()->hasPermission('rental_inspections.archive') && (! $inspection->isEvidenceRecord() || auth()->user()->hasPermission('rental_inspections.archive_completed')))
                    <form method="POST" action="{{ route('corex.rental-inspections.destroy', $inspection) }}" onsubmit="return confirm('Archive this inspection?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                    @endif
                @endif
                @if($inspection->trashed())
                    @permission('rental_inspections.restore')
                    <form method="POST" action="{{ route('corex.rental-inspections.restore', $inspection->id) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                    </form>
                    @endpermission
                @endif
            @endif
        </div>

        <form id="cancel-inspection-form" method="POST" action="{{ route('corex.rental-inspections.cancel', $inspection) }}" class="hidden space-y-2 pt-2">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>

        {{-- §43 — reschedule: keeps the old date/time/inspector as history
             (RentalInspectionReschedule), re-syncs the calendar event,
             re-notifies the parties. Reason optional, unlike cancel. --}}
        <form id="reschedule-inspection-form" method="POST" action="{{ route('corex.rental-inspections.reschedule', $inspection) }}" class="hidden space-y-2 pt-2">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-medium">New date</label>
                    <input type="date" name="scheduled_for" required value="{{ old('scheduled_for', $inspection->scheduled_for?->toDateString()) }}" min="{{ now()->toDateString() }}" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">New time</label>
                    <input type="time" name="scheduled_time" value="{{ old('scheduled_time', $inspection->scheduled_time ? substr((string) $inspection->scheduled_time, 0, 5) : '') }}" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">Duration (minutes)</label>
                    <input type="number" name="scheduled_duration_minutes" min="5" max="1440" value="{{ old('scheduled_duration_minutes', $inspection->scheduled_duration_minutes) }}" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">Inspector</label>
                    <select name="inspector_user_id" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        @foreach($inspectorOptions as $inspectorOption)
                            <option value="{{ $inspectorOption->id }}" @selected(old('inspector_user_id', $inspection->inspector_user_id) == $inspectorOption->id)>{{ $inspectorOption->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label class="text-xs font-medium">Reason for the change (optional)</label>
            <textarea name="reason" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-primary text-xs">Confirm reschedule</button>
        </form>

        @if($inspection->reschedules->isNotEmpty())
        <div class="pt-2 text-xs" style="color: var(--text-muted);">
            <p class="font-semibold mb-1" style="color: var(--text-secondary);">Reschedule history</p>
            @foreach($inspection->reschedules as $change)
                <div class="py-1" style="border-top: 1px solid var(--border);">
                    {{ $change->created_at->format('Y-m-d H:i') }} — {{ $change->changedBy?->name ?? 'Unknown' }} moved it
                    from {{ $change->old_scheduled_for?->format('Y-m-d') ?? '—' }}{{ $change->old_scheduled_time ? ' '.substr((string) $change->old_scheduled_time, 0, 5) : '' }}
                    to {{ $change->new_scheduled_for?->format('Y-m-d') ?? '—' }}{{ $change->new_scheduled_time ? ' '.substr((string) $change->new_scheduled_time, 0, 5) : '' }}
                    @if($change->reason) — {{ $change->reason }} @endif
                </div>
            @endforeach
        </div>
        @endif

        @if($inspection->notifications->isNotEmpty())
        <div class="pt-2 text-xs" style="color: var(--text-muted);">
            <p class="font-semibold mb-1" style="color: var(--text-secondary);">Notifications sent</p>
            @foreach($inspection->notifications as $notification)
                <div class="py-1" style="border-top: 1px solid var(--border);">
                    @if($notification->event === 'invitation_manual')
                        {{-- §45.5 — an invitation given off the system, recorded with the real time it was given. --}}
                        {{ ($notification->occurred_at ?? $notification->created_at)->format('Y-m-d H:i') }} —
                        Invitation recorded manually: {{ ucfirst($notification->party_role) }}
                        ({{ $notification->recipientContact?->full_name ?? $notification->recipientUser?->name ?? '—' }})
                        — {{ $notification->method }}{{ $notification->sentBy ? ', recorded by ' . $notification->sentBy->name : '' }}
                    @else
                    {{ $notification->created_at->format('Y-m-d H:i') }} —
                    {{ ucfirst($notification->event) }}: {{ ucfirst($notification->party_role) }}
                    ({{ $notification->recipientContact?->full_name ?? $notification->recipientUser?->name ?? '—' }})
                    via {{ ucfirst($notification->channel) }} —
                    <span style="color: {{ $notification->status === 'sent' ? 'var(--ds-green, #059669)' : ($notification->status === 'failed' ? 'var(--ds-crimson)' : 'var(--text-muted)') }};">{{ ucfirst($notification->status) }}</span>
                    @if($notification->error) ({{ $notification->error }}) @endif
                    @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- §45.6 (Build I-4) — who the completed report went to, and what happened. --}}
    @include('corex.rental-inspections.partials._copies-sent', ['inspection' => $inspection])

    {{-- §45.8 (Build I-6b) — History: who did what to this inspection, and when. Append-only; newest first. --}}
    <div id="history" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="inspection-history">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-sm font-semibold">History</h2>
            <form method="GET" action="{{ route('corex.rental-inspections.show', $inspection) }}#history" class="flex items-center gap-2">
                <select name="history_event" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);" onchange="this.form.submit()">
                    <option value="">All events ({{ array_sum($historyEvents) }})</option>
                    @foreach($historyEvents as $eventKey => $eventCount)
                        <option value="{{ $eventKey }}" @selected($historyFilter === $eventKey)>{{ \App\Models\RentalInspectionAuditLog::EVENT_LABELS[$eventKey] ?? $eventKey }} ({{ $eventCount }})</option>
                    @endforeach
                </select>
                @if($historyFilter !== '')
                    <a href="{{ route('corex.rental-inspections.show', $inspection) }}#history" class="text-xs underline" style="color: var(--text-muted);">Clear</a>
                @endif
            </form>
        </div>
        @forelse($historyRows as $entry)
            <div class="text-sm py-1.5" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span style="color: var(--text-primary);"><span class="font-semibold">{{ $entry->eventLabel() }}</span> — {{ $entry->summary ?? '' }}</span>
                    <span class="text-xs flex-none" style="color: var(--text-muted);">{{ $entry->created_at?->format('d M Y H:i') }} · {{ $entry->user?->name ?? 'System' }}</span>
                </div>
                @if($entry->event === 'details_edited' && is_array($entry->after))
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                        @foreach($entry->after as $field => $newValue)
                            {{ str_replace('_', ' ', $field) }}: {{ ($entry->before[$field] ?? null) !== null && ($entry->before[$field] ?? '') !== '' ? $entry->before[$field] : '(empty)' }} → {{ $newValue !== null && $newValue !== '' ? $newValue : '(empty)' }}@if(! $loop->last); @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">{{ $historyFilter !== '' ? 'No history of that kind on this inspection.' : 'Nothing has been recorded against this inspection yet.' }}</p>
        @endforelse
        @if($historyRows->count() >= 200)
            <p class="text-xs" style="color: var(--text-muted);">Showing the 200 most recent entries.</p>
        @endif
    </div>

    @if($inspection->discrepancies->isNotEmpty())
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Discrepancies</h2>
        @foreach($inspection->discrepancies as $discrepancy)
            <div class="text-sm space-y-1" style="border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                <div class="flex items-center justify-between">
                    <span>{{ $discrepancy->item?->label ?? 'Unknown item' }}</span>
                    @if($discrepancy->resolved_at)
                        <span class="ds-badge ds-badge-success">Resolved</span>
                    @else
                        <span class="ds-badge ds-badge-danger">Unresolved</span>
                    @endif
                </div>
                <div class="text-xs" style="color: var(--text-muted);">
                    {{ $discrepancy->observations->pluck('condition')->map(fn ($c) => ucfirst($c))->implode(' vs ') }}
                </div>
                @if($discrepancy->resolved_at)
                    <div class="text-xs" style="color: var(--text-muted);">Resolved by {{ $discrepancy->resolvedBy?->name }}: {{ $discrepancy->resolution_note }}</div>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    @if($comparisonRows !== null)
        {{-- Johan's ruling, 2026-09-23 — §2 (predecessor left/read-only,
             this inspection's own value right), §3 (row shows the
             immediate predecessor's value; the full run is on demand, via
             plain <details> — no Alpine, nothing here can fall into the
             :style-clobber trap that cost four rounds on the recording
             screen tonight). Replaces the flat list below entirely once a
             predecessor exists — §6, the FIRST inspection in a chain has
             none, and falls through to that flat list unchanged. --}}
        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold">
                Compared against the {{ $inspection->previousInspection->type }}-inspection
                <span class="text-xs" style="color: var(--text-muted);">({{ $inspection->previousInspection->scheduled_for?->format('Y-m-d') ?? $inspection->previousInspection->created_at->format('Y-m-d') }})</span>
            </h2>
            @foreach($comparisonRows as $roomId => $roomRows)
                @php
                    // Shorthand @php(...) form mis-parses on a nested-paren
                    // expression like first()->room (it closes on the FIRST
                    // ")" it finds — from first(), not the statement's own
                    // end — corrupting every directive compiled after it in
                    // the whole file. Block form has no such fragility.
                    $room = $roomRows->first()->room;
                @endphp
                <div class="space-y-1.5">
                    <h3 class="text-xs font-bold uppercase tracking-wide" style="color: var(--text-secondary);">{{ $room?->label ?? 'General' }}</h3>
                    <table class="w-full text-sm" style="border-collapse: collapse;">
                        <thead>
                            <tr class="text-xs" style="color: var(--text-muted);">
                                <th class="text-left font-normal py-1">Item</th>
                                <th class="text-left font-normal py-1">Previous</th>
                                <th class="text-left font-normal py-1">Current</th>
                                <th class="text-left font-normal py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($roomRows as $row)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="py-1.5 align-top">{{ $row->item->label }}</td>
                                    <td class="py-1.5 align-top">
                                        @if($row->predecessor)
                                            <span class="ds-badge {{ $conditionBadgeClass($row->predecessor->condition) }}">{{ ucfirst($row->predecessor->condition) }}</span>
                                            @if($row->predecessor->notes)
                                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $row->predecessor->notes }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs" style="color: var(--text-muted);">—</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 align-top">
                                        @if($row->current)
                                            <span class="ds-badge {{ $conditionBadgeClass($row->current->condition) }}">{{ ucfirst($row->current->condition) }}</span>
                                            @if($row->current->notes)
                                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $row->current->notes }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs" style="color: var(--text-muted);">Not yet recorded</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 align-top">
                                        @if($row->history->count() > 1)
                                            <details>
                                                <summary class="text-xs cursor-pointer" style="color: var(--brand-icon, #0ea5e9);">Full history ({{ $row->history->count() }})</summary>
                                                <div class="text-xs mt-1 space-y-0.5" style="color: var(--text-secondary);">
                                                    @foreach($row->history as $entry)
                                                        <div>{{ ucfirst($entry->inspection_type) }} ({{ $entry->scheduled_for?->format('Y-m-d') ?? '—' }}): {{ ucfirst($entry->observation->condition) }}</div>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @else
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Observations</h2>
        @forelse($inspection->observations as $observation)
            <div class="text-sm flex items-center justify-between" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span>{{ $observation->item?->label ?? 'Unknown item' }}
                    <span class="ds-badge {{ $conditionBadgeClass($observation->condition) }}">{{ ucfirst($observation->condition) }}</span>
                    @if($observation->notes) — {{ $observation->notes }} @endif
                </span>
                <span class="text-xs" style="color: var(--text-muted);">
                    {{ $observation->observedByUser?->name ?? $observation->observedByContact?->full_name }}
                    · {{ $observation->created_at?->format('Y-m-d H:i') }}
                    @if($observation->photos->isNotEmpty()) · {{ $observation->photos->count() }} photo(s) @endif
                </span>
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No observations recorded yet.</p>
        @endforelse
    </div>
    @endif

    {{--
        .ai/specs/rental-work-orders.md §15 (AT-447) — Johan's requirement:
        at the end of an inspection, every item marked faulty/damaged can
        become a fault report, work order, or job card straight from here,
        linked back to this inspection/lease/property. Shown on every
        inspection, whatever its status — a draft being finished can raise
        follow-up just as well as a completed one.

        "Needs follow-up" is the agency's EXISTING condition-severity
        configuration (RentalInspectionSetting::conditionNeedsFollowUpFor()
        — red/amber, the same vocabulary the recording screen's own "Needs
        attention" filter already uses), never a hardcoded condition value
        and never "not this agency's baseline" (an earlier build of this
        block used that and wrongly listed N/A and a stray unmapped
        condition value as if they were faults — Johan, 2026-10-05, QA1
        property walk). No block at all when nothing qualifies.

        Per-row mini-actions are always single-item (no ticking needed,
        idempotent — an already-raised item shows its linked record(s)
        instead). "Select all" + the shared bar below the list are for
        "combine into one" — tick several rows, then one of the three
        buttons raises ONE record covering all of them (or, if "combine" is
        left unticked, one record PER ticked item — the stated default).
    --}}
    @if($followUpObservations->isNotEmpty())
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Follow-up ({{ $followUpObservations->count() }})</h2>
            <label class="text-xs flex items-center gap-1" style="color: var(--text-muted);">
                <input type="checkbox" onclick="document.querySelectorAll('#follow-up-shared-form input[name=\'observation_ids[]\']').forEach(cb => cb.checked = this.checked);">
                Select all
            </label>
        </div>
            <form method="POST" action="{{ route('corex.rental-inspections.follow-up.fault-reports', $inspection) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="rental_inspection_id" value="{{ $inspection->id }}">
                @foreach($followUpObservations as $observation)
                    @php
                        $existingFaultReports = $followUpFaultReportsByObservation->get($observation->id, collect());
                        $existingWorkOrders = $followUpWorkOrdersByObservation->get($observation->id, collect());
                        $roomLabel = $observation->item?->room?->label ?? 'General';
                        $itemLabel = $observation->item?->label ?? 'Unknown item';
                    @endphp
                    <div class="text-sm space-y-1" style="border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                        <div class="flex items-start justify-between gap-2">
                            <label class="flex items-start gap-2">
                                <input type="checkbox" name="observation_ids[]" value="{{ $observation->id }}" class="mt-1" form="follow-up-shared-form">
                                <span>
                                    {{ $roomLabel }} — {{ $itemLabel }}
                                    <span class="ds-badge {{ $conditionBadgeClass($observation->condition) }}">{{ ucfirst(str_replace('_', ' ', $observation->condition)) }}</span>
                                    @if($observation->notes) — <span style="color: var(--text-muted);">{{ $observation->notes }}</span> @endif
                                    @if($observation->photos->isNotEmpty())
                                        <span class="text-xs" style="color: var(--text-muted);">· {{ $observation->photos->count() }} photo(s)</span>
                                    @endif
                                    {{-- §45.7a item 5 — out-inspection only: how this compares with move-in. Never preselects anything. --}}
                                    @if(!empty($followUpMarkers[$observation->id]['label']))
                                        <span class="text-xs font-semibold" style="color: {{ ($followUpMarkers[$observation->id]['key'] ?? '') === 'same' ? 'var(--text-muted)' : '#b45309' }};" data-qa="follow-up-marker-{{ $observation->id }}">· {{ $followUpMarkers[$observation->id]['label'] }}</span>
                                    @endif
                                </span>
                            </label>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 text-xs" style="padding-left: 24px;">
                            @forelse($existingFaultReports as $faultReport)
                                @feature('rental-faults')<a href="{{ route('corex.rental-fault-reports.show', $faultReport) }}" style="color: var(--brand-icon, #2563eb);">Fault report #{{ $faultReport->id }} ({{ ucfirst(str_replace('_', ' ', $faultReport->status)) }})</a>@else Fault report #{{ $faultReport->id }} ({{ ucfirst(str_replace('_', ' ', $faultReport->status)) }}) @endfeature
                            @empty
                                <button type="submit" formaction="{{ route('corex.rental-inspections.follow-up.fault-reports', $inspection) }}" name="observation_ids[]" value="{{ $observation->id }}" class="corex-btn-outline text-xs">Create fault report</button>
                            @endforelse

                            @forelse($existingWorkOrders as $workOrder)
                                @feature('rental-work-orders')<a href="{{ route('corex.rental-work-orders.show', $workOrder) }}" style="color: var(--brand-icon, #2563eb);">Work order #{{ $workOrder->id }} ({{ ucfirst(str_replace('_', ' ', $workOrder->status)) }})</a>@else Work order #{{ $workOrder->id }} ({{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}) @endfeature
                                @if($workOrder->jobCard)
                                    @feature('rental-job-cards')<a href="{{ route('corex.rental-job-cards.show', $workOrder->jobCard) }}" style="color: var(--brand-icon, #2563eb);">Job card #{{ $workOrder->jobCard->id }} ({{ ucfirst(str_replace('_', ' ', $workOrder->jobCard->status)) }})</a>@else Job card #{{ $workOrder->jobCard->id }} ({{ ucfirst(str_replace('_', ' ', $workOrder->jobCard->status)) }}) @endfeature
                                @endif
                            @empty
                                @feature('rental-work-orders')
                                <a href="{{ route('corex.rental-work-orders.create', ['rental_inspection_id' => $inspection->id, 'observation_ids' => [$observation->id]]) }}" class="corex-btn-outline text-xs">Create work order</a>
                                @endfeature
                                {{-- 2026-10-05 rebuild — a job card is its own screen now, not an
                                     assignment_type=internal work order; the observation becomes a
                                     pre-seeded (editable, nothing saved yet) task on the job card draft. --}}
                                @feature('rental-job-cards')
                                <a href="{{ route('corex.rental-job-cards.create', ['rental_inspection_id' => $inspection->id, 'observation_ids' => [$observation->id]]) }}" class="corex-btn-outline text-xs">Create job card (our team)</a>
                                @endfeature
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </form>

            {{-- The combine/batch bar — ticked checkboxes above belong to
                 THIS form via the "form" attribute, even though they are
                 visually nested in the per-row form above. Which button is
                 clicked decides where the ticked set goes: fault reports
                 are created directly (this form's own default method/
                 action); work orders/job cards redirect (GET) to the
                 existing create screen, carrying every ticked id — only the
                 CLICKED button's own name/value pair is submitted, so
                 assignment_type is absent unless "Create job card" was
                 pressed (standard HTML submit-button behaviour, no JS
                 needed). --}}
            <form id="follow-up-shared-form" method="POST" action="{{ route('corex.rental-inspections.follow-up.fault-reports', $inspection) }}" class="flex flex-wrap items-center gap-3 pt-2" style="border-top: 1px solid var(--border);">
                @csrf
                <input type="hidden" name="rental_inspection_id" value="{{ $inspection->id }}">
                <label class="text-xs flex items-center gap-1">
                    <input type="checkbox" name="combine" value="1"> Combine ticked items into one
                </label>
                <button type="submit" class="corex-btn-outline text-xs">Create fault report</button>
                @feature('rental-work-orders')
                <button type="submit" formmethod="GET" formaction="{{ route('corex.rental-work-orders.create', ['rental_inspection_id' => $inspection->id]) }}" class="corex-btn-outline text-xs">Create work order</button>
                @endfeature
                {{-- 2026-10-05 rebuild — job cards are their own screen; ticked
                     observation_ids[] (and combine) still serialize via plain GET form submit. --}}
                @feature('rental-job-cards')
                <button type="submit" formmethod="GET" formaction="{{ route('corex.rental-job-cards.create', ['rental_inspection_id' => $inspection->id]) }}" class="corex-btn-outline text-xs">Create job card (our team)</button>
                @endfeature
            </form>
    </div>
    @endif

    </div>
    {{-- Side column: scans, signatures — the paperwork evidence trail. --}}
    <div class="col-span-3 lg:col-span-1 space-y-4">

    {{-- .ai/specs/rental-inspection-form.md §13 — the OMR scan reader, part 2
         of cc5's printable-form job. Upload the wet-ink-marked printed form
         back in; nothing is applied to the inspection until a human confirms
         on the review screen. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Scanned forms</h2>
        @permission('rental_inspections.create')
            <form method="POST" action="{{ route('corex.rental-inspections.scans.store', $inspection) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                @csrf
                <input type="file" name="scan" accept="application/pdf,image/jpeg,image/png,image/heic,image/heif" required class="text-xs">
                <button type="submit" class="corex-btn-outline text-xs">Upload scan</button>
            </form>
        @endpermission
        @forelse($inspection->scans as $scan)
            @php
                $scanStatusLabel = match ($scan->status) {
                    'needs_review' => 'Needs review',
                    'applied' => 'Applied',
                    'version_mismatch' => 'Form version mismatch',
                    'failed' => 'Could not be read',
                    default => 'Processing',
                };
                $scanStatusBadge = match ($scan->status) {
                    'applied' => 'ds-badge-success',
                    'needs_review' => 'ds-badge-info',
                    'version_mismatch', 'failed' => 'ds-badge-danger',
                    default => 'ds-badge-muted',
                };
            @endphp
            <div class="text-sm flex items-center justify-between" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span>{{ $scan->original_filename }}
                    <span class="ds-badge {{ $scanStatusBadge }}">{{ $scanStatusLabel }}</span>
                </span>
                <span class="text-xs flex items-center gap-2" style="color: var(--text-muted);">
                    {{ $scan->uploadedBy?->name }} · {{ $scan->created_at?->format('Y-m-d H:i') }}
                    @if(in_array($scan->status, ['needs_review', 'applied'], true))
                        <a href="{{ route('corex.rental-inspections.scans.review', [$inspection, $scan]) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">Review</a>
                    @endif
                    <a href="{{ route('corex.rental-inspections.scans.download', [$inspection, $scan]) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">Download original</a>
                    @permission('rental_inspections.create')
                        <form method="POST" action="{{ route('corex.rental-inspections.scans.destroy', [$inspection, $scan]) }}" onsubmit="return confirm('Archive this scan?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="underline" style="color: var(--ds-crimson); background: none; border: 0;">Archive</button>
                        </form>
                    @endpermission
                </span>
            </div>
            @if($scan->failure_reason)
                <p class="text-xs" style="color: var(--ds-crimson);">{{ $scan->failure_reason }}</p>
            @endif
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No scans uploaded yet.</p>
        @endforelse
    </div>

    {{-- §45.5 (Build I-3) — who attended, in what capacity, and what is on record about each party's
         invitation. Facts only. Read-only here: attendance is recorded on the property's Inspections tab. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="attendance-summary">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold">Attendance</h2>
            <span class="text-xs" style="color: var(--text-muted);">{{ $attendanceBoard['recorded'] }} of {{ $attendanceBoard['expected'] }} recorded &middot; {{ $attendanceBoard['attended'] }} attended</span>
        </div>
        @foreach($attendanceBoard['rows'] as $row)
            @php
                $att = $row['attendance'];
            @endphp
            <div class="text-sm py-2" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span style="color: var(--text-primary);">{{ $row['name'] ?: 'Unnamed' }} <span class="text-xs" style="color: var(--text-muted);">({{ ucfirst($row['party_role']) }})</span></span>
                    <span class="text-xs font-semibold" style="color: var(--text-primary);">
                        @if(! $att)
                            Not yet recorded
                        @elseif($att['outcome'] === 'did_not_attend')
                            Did not attend
                        @else
                            Attended{{ $att['attended_as'] !== 'self' ? ' — ' . ($attendedAsLabels[$att['attended_as']] ?? $att['attended_as']) . ($att['attendee_name'] ? ' (' . $att['attendee_name'] . ')' : '') : '' }}{{ $att['arrived_at'] ? ', arrived ' . $att['arrived_at'] : '' }}
                        @endif
                    </span>
                </div>
                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                    {{ collect($row['invitation']['lines'])->pluck('text')->implode(' · ') }}
                    @if($att) · Recorded by {{ $att['recorded_by'] ?? 'unknown' }}, {{ $att['recorded_at_label'] }} @endif
                </div>
            </div>
        @endforeach
        @foreach($attendanceBoard['others'] as $other)
            <div class="text-sm py-2" style="border-bottom: 1px solid var(--border);">
                <span style="color: var(--text-primary);">{{ $other['attendee_name'] ?: 'Unnamed' }}</span>
                <span class="text-xs" style="color: var(--text-muted);">({{ $attendedAsLabels[$other['attended_as']] ?? $other['attended_as'] }}) — attended{{ $other['arrived_at'] ? ', arrived ' . $other['arrived_at'] : '' }}</span>
            </div>
        @endforeach
        <p class="text-xs" style="color: var(--text-muted);">Attendance is recorded on the property's Inspections tab.</p>
    </div>

    @if($inspection->signatures->isNotEmpty())
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Signatures</h2>
        {{--
            §15.5/§15.8, Stage 5 — the unambiguous rendering Johan's ruling
            requires: "a refusal must never be able to look like a signature,
            on screen or on the PDF." Branches on `disposition` alone (never
            on whether party_signature_path happens to be null), and a
            signed row's image and a refused row's reason block share no
            markup — different shape, not just different colour, so this
            still reads correctly in black-and-white print. Neither is
            styled as an error or a warning: both are simply facts about how
            the inspection ended (Johan: "none of these should feel like
            [an error state] in the UI").
        --}}
        {{-- §17.5's own original design deliberately keeps a superseded row
             visible here (with its own "Superseded" note below, pointing at
             the replacement) rather than hiding it — unlike the report PDF/
             public page/inventory show page, which show the live row only.
             Kept as-is; not changed by this pass. --}}
        @foreach($inspection->signatures as $signature)
            @php
                $partyLabel = match($signature->party_role) {
                    'agent' => 'Agent',
                    'landlord' => 'Landlord' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                    default => 'Tenant' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                };
                $reasonLabel = collect($refusalReasonPresets)->firstWhere('key', $signature->refusal_reason_preset)['label']
                    ?? $signature->refusal_reason_preset;
                // §45.5 — a party recorded as having not attended has NO signature; it is not a refusal.
                $didNotAttend = collect($attendanceBoard['rows'])->contains(fn ($r) => $r['party_role'] === $signature->party_role
                    && (int) ($r['contact_id'] ?? 0) === (int) ($signature->party_contact_id ?? 0)
                    && ($r['attendance']['outcome'] ?? null) === 'did_not_attend');
            @endphp
            <div class="text-sm py-2" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span style="color: var(--text-primary);">{{ $partyLabel }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $signature->disposition_recorded_at?->format('Y-m-d H:i') }}</span>
                </div>
                @if($signature->disposition === 'signed')
                    <div class="mt-1.5">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Signed</span>
                        @if($signature->signed_by_name)
                            <span class="text-xs" style="color: var(--text-secondary);">by {{ $signature->signed_by_name }} ({{ $attendedAsLabels[$signature->signing_capacity] ?? $signature->signing_capacity }})</span>
                        @endif
                        @if($signature->party_signature_path)
                            <div class="mt-1">
                                <img src="{{ $signature->fileUrl('signature') }}" alt="{{ $partyLabel }}'s signature"
                                     style="max-height: 60px; background: #fff; border: 1px solid var(--border); border-radius: 4px; padding: 4px;">
                            </div>
                        @endif
                    </div>
                @elseif($signature->disposition === 'wet_ink')
                    {{--
                        .ai/specs/rental-inspections.md §16 — evidence of a real
                        signature, on paper, never rendered as an e-signature: no
                        signature-image markup, no "Signed" label, its own shape
                        entirely so it cannot be mistaken for either the signed or
                        the refused block above, cold, eighteen months from now.
                    --}}
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        {{-- Conductor brief 2026-09-29 — wording standardised
                             to "Signed on paper — scan on file" everywhere
                             this disposition renders (report PDF, public
                             page, live recording screen); this page
                             previously read "Signed on paper (wet-ink)". --}}
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Signed on paper — scan on file</span>
                        @if($signature->superseded_at)
                            <div class="text-xs mt-0.5" style="color: var(--ds-amber, #d97706);">
                                Superseded — replaced by a corrected upload{{ $signature->supersededBy ? ' below' : '' }}.
                            </div>
                        @elseif($signature->wet_ink_upload_path)
                            <div class="text-xs mt-0.5">
                                <a href="{{ $signature->fileUrl('wet-ink') }}" target="_blank" rel="noopener" class="underline" style="color: var(--brand-icon, #0ea5e9);">View uploaded page</a>
                            </div>
                        @endif
                        @if($signature->recordedByUser)
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                Uploaded by {{ $signature->recordedByUser->name }} on {{ $signature->disposition_recorded_at?->format('Y-m-d H:i') }} — attested by the agent's own signature below.
                            </div>
                        @endif
                    </div>
                @elseif($signature->disposition === 'awaiting_wet_ink')
                    {{-- Conductor brief 2026-09-29 — this page had no branch
                         for this new disposition at all before this pass; it
                         fell through to the @else block below and rendered
                         an awaiting party as "Refused to sign" with a bogus
                         reason label (refusal_reason_preset is null for this
                         disposition — a real gap this fix closes). --}}
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Awaiting paper signature</span>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">Sent {{ $signature->disposition_recorded_at?->format('Y-m-d H:i') }} — upload the scan once it comes back.</div>
                    </div>
                @elseif($didNotAttend && $signature->refusal_reason_preset === 'not_present')
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">No signature — did not attend</span>
                    </div>
                @else
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">{{ $didNotAttend ? 'No signature — did not attend' : 'Refused to sign' }}</span>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">
                            Reason: {{ $reasonLabel }}{{ $signature->refusal_reason_note ? ' — ' . $signature->refusal_reason_note : '' }}
                        </div>
                        @if($signature->recordedByUser)
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                Recorded by {{ $signature->recordedByUser->name }} — attested by the agent's own signature below.
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    {{-- .ai/specs/rental-inventory.md §4 — reachable from where the work
         happens, not only the sidebar list (Johan, 2026-09-22). --}}
    @include('corex.rental-inventories.partials._related-inventories', ['property' => $inspection->property])

    </div>
    </div>
</div>
@endsection
