@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §14 (AT-442) — the job card detail screen. Full-width. --}}

@php
    $statusBadgeClass = match ($jobCard->status) {
        'completed' => 'ds-badge-success',
        'cancelled' => 'ds-badge-danger',
        'in_progress', 'scheduled' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
    $isOpen = !in_array($jobCard->status, ['completed', 'cancelled'], true);
@endphp

@section('content')
<div class="p-6 max-w-7xl mx-auto space-y-4">
    {{-- AT-442 fix #7 — session('success') is already surfaced by the app's
         standard toast (components.toast-notifications reads the same flash
         key on DOMContentLoaded); an inline banner here showed the same
         message twice. Same fix as leases/show.blade.php (AT-444 follow-up 2). --}}
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- AT-442 fix #4 — same context bar as the work order show page. --}}
    <x-rental-context-bar :property="$jobCard->property" :lease="$jobCard->lease" current="work_orders" />

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $jobCard->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $jobCard->status)) }}</span>
            <span class="text-xs" style="color: var(--text-muted);">{{ $jobCard->property?->buildDisplayAddress() ?? 'Unknown property' }}</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.rental-job-cards.print', $jobCard) }}" target="_blank" class="corex-btn-outline text-xs">Print job card</a>
            <a href="{{ route('corex.rental-work-orders.show', $jobCard->rental_work_order_id) }}" class="corex-btn-outline text-xs">View work order</a>
            <a href="{{ route('corex.rental-job-cards.index') }}" class="corex-btn-outline text-xs">&larr; All job cards</a>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-4">
        <div class="col-span-2 space-y-4">
            {{-- Details --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
                <div class="grid grid-cols-2 gap-3 text-sm" x-show="!editing">
                    <div><span style="color: var(--text-muted);">Tenancy:</span> {{ $jobCard->lease?->tenantNames() ?? 'None — vacancy period' }}</div>
                    <div><span style="color: var(--text-muted);">Access notes:</span> {{ $jobCard->access_notes ?? '—' }}</div>
                    {{-- §15 (AT-447) — "From inspection <type> <date>" back-link, reached via the linked work order (a job card has no inspection FK of its own). --}}
                    @if($jobCard->workOrder?->reportedInspectionObservation?->inspection)
                        <div><span style="color: var(--text-muted);">From inspection:</span> <a href="{{ route('corex.rental-inspections.show', $jobCard->workOrder->reportedInspectionObservation->inspection) }}" class="underline">{{ ucfirst(str_replace('_', '-', $jobCard->workOrder->reportedInspectionObservation->inspection->type)) }}-inspection {{ $jobCard->workOrder->reportedInspectionObservation->inspection->scheduled_for?->format('Y-m-d') ?? $jobCard->workOrder->reportedInspectionObservation->inspection->created_at?->format('Y-m-d') }}</a></div>
                    @endif
                </div>
                @permission('rental_job_cards.create')
                @if($jobCard->status === \App\Models\RentalJobCard::STATUS_DRAFT)
                <div x-show="!editing" class="pt-1">
                    <button type="button" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
                </div>
                <form x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-job-cards.update', $jobCard) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="text-xs font-medium">Title</label>
                        <input type="text" name="title" required maxlength="191" value="{{ old('title', $jobCard->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="text-xs font-medium">Access notes</label>
                        <textarea name="access_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('access_notes', $jobCard->access_notes) }}</textarea>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                        <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
                    </div>
                </form>
                @endif
                @endpermission

                @if($jobCard->status === 'cancelled')
                    <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $jobCard->cancelled_at?->format('Y-m-d') }} by {{ $jobCard->cancelledByUser?->name }}: {{ $jobCard->cancel_reason }}</p>
                @endif
            </div>

            {{-- Tasks --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Tasks</h2>
                @forelse($jobCard->tasks as $task)
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <form method="POST" action="{{ route('corex.rental-job-cards.tasks.toggle', [$jobCard, $task]) }}" class="flex items-center gap-2">
                            @csrf
                            <button type="submit" class="flex items-center gap-2 text-left">
                                <input type="checkbox" @checked($task->is_done) onclick="return false;" class="rounded">
                                <span style="{{ $task->is_done ? 'text-decoration: line-through; color: var(--text-muted);' : '' }}">{{ $task->description }}</span>
                            </button>
                        </form>
                        @permission('rental_job_cards.create')
                        <form method="POST" action="{{ route('corex.rental-job-cards.tasks.destroy', [$jobCard, $task]) }}" onsubmit="return confirm('Archive this task?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                        </form>
                        @endpermission
                    </div>
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">No tasks yet.</p>
                @endforelse
                @permission('rental_job_cards.create')
                <form method="POST" action="{{ route('corex.rental-job-cards.tasks.store', $jobCard) }}" class="flex items-end gap-2 pt-2">
                    @csrf
                    <input type="text" name="description" required maxlength="500" placeholder="Add a task" class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <button type="submit" class="corex-btn-outline text-xs">Add</button>
                </form>
                @if($archivedTasks->isNotEmpty())
                    <button type="button" onclick="document.getElementById('archived-tasks').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedTasks->count() }} archived task(s)</button>
                    <ul id="archived-tasks" class="hidden space-y-1 text-sm pt-1">
                        @foreach($archivedTasks as $at)
                            <li class="flex items-center justify-between gap-2">
                                <span style="color: var(--text-muted);">{{ $at->description }}</span>
                                <form method="POST" action="{{ route('corex.rental-job-cards.tasks.restore', [$jobCard, $at->id]) }}">
                                    @csrf
                                    <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @endpermission
            </div>

            {{-- Lines --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Parts &amp; labour</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr style="color: var(--text-muted);">
                            <th class="text-left py-1">Description</th>
                            <th class="text-left py-1">Type</th>
                            @if($pricesOn)
                                <th class="text-left py-1">Qty</th>
                                <th class="text-left py-1">Unit price</th>
                                <th class="text-left py-1">Line total</th>
                            @endif
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($jobCard->lines as $line)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="py-1">{{ $line->description }}</td>
                                <td class="py-1">{{ ucfirst($line->type) }}</td>
                                @if($pricesOn)
                                    <td class="py-1">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }} {{ $line->unit }}</td>
                                    <td class="py-1">{{ $line->unit_price !== null ? 'R' . number_format((float) $line->unit_price, 2) : '—' }}</td>
                                    <td class="py-1">{{ $line->line_total !== null ? 'R' . number_format((float) $line->line_total, 2) : '—' }}</td>
                                @endif
                                <td class="py-1 text-right">
                                    @permission('rental_job_cards.create')
                                    <form method="POST" action="{{ route('corex.rental-job-cards.lines.destroy', [$jobCard, $line]) }}" onsubmit="return confirm('Archive this line?');" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                                    </form>
                                    @endpermission
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-2 text-xs" style="color: var(--text-muted);">No lines yet.</td></tr>
                        @endforelse
                    </tbody>
                    @if($pricesOn)
                    <tfoot>
                        <tr style="border-top: 1px solid var(--border); font-weight: 600;">
                            <td colspan="4" class="py-1 text-right">
                                Total
                                {{-- AT-442 fix #6 — the landlord's no-approval limit and whether this total is within or over it, next to the total itself. --}}
                                <div class="text-xs font-normal" style="color: {{ ($jobCard->total_amount ?? 0) <= $noApprovalThreshold ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">
                                    @if(($jobCard->total_amount ?? 0) <= $noApprovalThreshold)
                                        Within the landlord's R{{ number_format($noApprovalThreshold, 2) }} no-approval limit
                                    @else
                                        Over the landlord's R{{ number_format($noApprovalThreshold, 2) }} no-approval limit — owner approval required
                                    @endif
                                </div>
                            </td>
                            <td class="py-1">R{{ number_format((float) ($jobCard->total_amount ?? 0), 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
                @permission('rental_job_cards.create')
                <form method="POST" action="{{ route('corex.rental-job-cards.lines.store', $jobCard) }}" class="grid grid-cols-6 gap-2 pt-2 items-end">
                    @csrf
                    <div>
                        <label class="text-xs">Catalogue item</label>
                        <select name="rental_catalogue_item_id" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);" onchange="this.form.description.value=''; this.form.type.disabled = !!this.value;">
                            <option value="">— Free text —</option>
                            @foreach($catalogueItems as $ci)
                                <option value="{{ $ci->id }}">{{ $ci->name }} ({{ ucfirst($ci->type) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs">Description</label>
                        <input type="text" name="description" maxlength="255" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        {{-- AT-442 fix #5 — a free-text line was always saved as
                             Labour with no way to choose. Disabled (so it never
                             posts) once a catalogue item is picked — that item
                             keeps its own type, see RentalJobCardService::addLine(). --}}
                        <label class="text-xs">Type</label>
                        <select name="type" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                            <option value="labour">Labour</option>
                            <option value="part">Part</option>
                        </select>
                    </div>
                    @if($pricesOn)
                        <div>
                            <label class="text-xs">Qty</label>
                            <input type="number" name="quantity" step="0.01" min="0.01" value="1" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                        </div>
                        <div>
                            <label class="text-xs">Unit price (R)</label>
                            <input type="number" name="unit_price" step="0.01" min="0" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                        </div>
                    @endif
                    <div>
                        <button type="submit" class="corex-btn-outline text-xs">Add line</button>
                    </div>
                </form>
                @endpermission
            </div>

            {{-- Photos (same pipeline as the linked work order) --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Photos</h2>
                @if($jobCard->workOrder->photos->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
                @else
                    <div class="grid grid-cols-4 gap-2">
                        @foreach($jobCard->workOrder->photos as $photo)
                            <div>
                                <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                                <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $photo->photo_type)) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                @permission('rental_job_cards.create')
                <form method="POST" action="{{ route('corex.rental-job-cards.photos.store', $jobCard) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <select name="photo_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        <option value="reported">Before</option>
                        <option value="in_progress">In progress</option>
                        <option value="completed">Completed (after)</option>
                    </select>
                    <input type="file" name="photo" accept="image/*" required class="text-xs">
                    <button type="submit" class="corex-btn-outline text-xs">Upload photo</button>
                </form>
                @endpermission
            </div>

            {{-- History --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">History</h2>
                <ul class="space-y-1 text-sm">
                    @foreach($jobCard->history() as $entry)
                        <li>
                            <span style="color: var(--text-muted);">{{ $entry['at']->format('Y-m-d H:i') }}</span>
                            —
                            {{ $entry['action'] }}
                            @if($entry['from'] || $entry['to'])
                                ({{ $entry['from'] ?? '—' }} &rarr; {{ $entry['to'] ?? '—' }})
                            @endif
                            @if($entry['note'])
                                — {{ $entry['note'] }}
                            @endif
                            @if($entry['actor'])
                                <span style="color: var(--text-muted);">({{ $entry['actor'] }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="space-y-4">
            {{-- Crew & schedule --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Crew &amp; schedule</h2>
                <div class="text-sm space-y-1">
                    <div><span style="color: var(--text-muted);">Assigned to:</span> {{ $jobCard->assignedUser?->name ?? '—' }}</div>
                    <div><span style="color: var(--text-muted);">Scheduled:</span> {{ $jobCard->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</div>
                    <div><span style="color: var(--text-muted);">Due:</span> {{ $jobCard->due_at?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
                @permission('rental_job_cards.create')
                @if($isOpen)
                <form method="POST" action="{{ route('corex.rental-job-cards.assign-crew', $jobCard) }}" class="flex gap-2">
                    @csrf
                    <select name="assigned_user_id" required class="flex-1 rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                        <option value="">Select crew member…</option>
                        @foreach($crew as $c)
                            <option value="{{ $c->id }}" @selected($jobCard->assigned_user_id === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="corex-btn-outline text-xs">Assign</button>
                </form>
                <form method="POST" action="{{ route('corex.rental-job-cards.schedule', $jobCard) }}" class="space-y-2">
                    @csrf
                    <input type="datetime-local" name="scheduled_at" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <input type="datetime-local" name="due_at" placeholder="Due" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <button type="submit" class="corex-btn-outline text-xs">Schedule</button>
                </form>
                @if($jobCard->status === \App\Models\RentalJobCard::STATUS_SCHEDULED)
                <form method="POST" action="{{ route('corex.rental-job-cards.start', $jobCard) }}">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">Mark in progress</button>
                </form>
                @endif
                @endif
                @endpermission
            </div>

            {{-- Quote to owner --}}
            @if($isOpen && in_array($jobCard->status, [\App\Models\RentalJobCard::STATUS_DRAFT, \App\Models\RentalJobCard::STATUS_QUOTED], true))
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Quote to owner</h2>
                @if($jobCard->quotes->isNotEmpty())
                    <ul class="space-y-1 text-xs">
                        @foreach($jobCard->quotes as $q)
                            <li>R{{ number_format((float) $q->amount, 2) }} — {{ $q->quote_date?->format('Y-m-d') }} @if($q->is_selected)<span class="ds-badge ds-badge-success">Selected</span>@endif</li>
                        @endforeach
                    </ul>
                @endif
                @permission('rental_job_cards.send_quote')
                {{-- AT-442 follow-up (item 8) — no landlord linked blocks the
                     send server-side (RentalJobCardService::sendToOwnerAsQuote());
                     offer the fix right here instead of just an error banner. --}}
                @if(!$jobCard->property?->landlordContact())
                    <p class="text-xs" style="color: var(--ds-crimson);">No landlord linked — link a landlord before sending the quote.
                        <a href="{{ route('corex.properties.show', ['property' => $jobCard->property_id, 'tab' => 'contacts']) }}" class="underline">Link landlord</a>
                    </p>
                @else
                <form method="POST" action="{{ route('corex.rental-job-cards.send-quote', $jobCard) }}" onsubmit="return confirm('Send this job card to the owner as a quote?');">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">Send to owner as quote</button>
                </form>
                @endif
                @endpermission
            </div>
            @endif

            {{-- Sign-off --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Sign-off</h2>
                <div class="text-sm space-y-1">
                    <div>Worker — done: {{ $jobCard->worker_signed_off_at ? $jobCard->workerSignedOffByUser?->name . ' (' . $jobCard->worker_signed_off_at->format('Y-m-d H:i') . ')' : '—' }}</div>
                    <div>Agent — checked: {{ $jobCard->agent_signed_off_at ? $jobCard->agentSignedOffByUser?->name . ' (' . $jobCard->agent_signed_off_at->format('Y-m-d H:i') . ')' : '—' }}</div>
                    <div>Tenant — confirmed fixed: {{ $jobCard->tenant_confirmed_at ? $jobCard->tenantConfirmedByUser?->name . ' (' . $jobCard->tenant_confirmed_at->format('Y-m-d H:i') . ')' : '—' }}</div>
                </div>
                @permission('rental_job_cards.sign_off')
                @if($isOpen)
                <div class="flex flex-col gap-2">
                    @unless($jobCard->worker_signed_off_at)
                    <form method="POST" action="{{ route('corex.rental-job-cards.worker-sign-off', $jobCard) }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Worker sign-off</button></form>
                    @endunless
                    @unless($jobCard->agent_signed_off_at)
                    <form method="POST" action="{{ route('corex.rental-job-cards.agent-sign-off', $jobCard) }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Agent sign-off</button></form>
                    @endunless
                    <form method="POST" action="{{ route('corex.rental-job-cards.tenant-confirm', $jobCard) }}" class="space-y-2">
                        @csrf
                        <textarea name="tenant_confirmation_note" rows="2" placeholder="Tenant confirmation note (optional)" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
                        <button type="submit" class="corex-btn-outline text-xs">Record tenant confirmation</button>
                    </form>
                </div>
                @endif
                @endpermission
            </div>

            {{-- Complete / cancel --}}
            @if($isOpen)
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @permission('rental_job_cards.sign_off')
                <form method="POST" action="{{ route('corex.rental-job-cards.complete', $jobCard) }}" onsubmit="return confirm('Mark this job card complete?');">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs w-full">Complete job card</button>
                </form>
                @endpermission
                @permission('rental_job_cards.cancel')
                <button type="button" onclick="document.getElementById('cancel-job-card-form').classList.toggle('hidden')" class="corex-btn-outline text-xs w-full">Cancel job card</button>
                <form id="cancel-job-card-form" method="POST" action="{{ route('corex.rental-job-cards.cancel', $jobCard) }}" class="hidden space-y-2 pt-2">
                    @csrf
                    <textarea name="cancel_reason" required placeholder="Reason for cancellation" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
                    <button type="submit" class="corex-btn-outline text-xs w-full" style="color: var(--ds-crimson);">Confirm cancel</button>
                </form>
                @endpermission
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
