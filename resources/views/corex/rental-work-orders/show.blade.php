@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §3/§3.4 — the work order detail screen. --}}

@php
    $statusBadgeClass = match ($workOrder->status) {
        'completed' => 'ds-badge-success',
        'reported', 'ordered', 'in_progress' => 'ds-badge-info',
        'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
    $isOpen = !in_array($workOrder->status, ['completed', 'cancelled'], true);
@endphp

@section('content')
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $workOrder->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}</span>
            <span class="text-xs" style="color: var(--text-muted);">{{ $workOrder->property?->buildDisplayAddress() ?? 'Unknown property' }}</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.rental-work-orders.pdf', $workOrder) }}" target="_blank" class="corex-btn-outline text-xs">Download PDF</a>
            <a href="{{ route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-xs">&larr; All work orders</a>
        </div>
    </div>

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
        <div class="grid grid-cols-2 gap-3 text-sm" x-show="!editing">
            <div class="col-span-2"><span style="color: var(--text-muted);">Description:</span> {{ $workOrder->description }}</div>
            <div><span style="color: var(--text-muted);">Tenancy:</span> {{ $workOrder->lease?->tenantNames() ?? 'None — vacancy period' }}</div>
            <div><span style="color: var(--text-muted);">Item:</span> {{ $workOrder->inspectionItem?->label ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Trade:</span> {{ $workOrder->trade_type ? ucfirst($workOrder->trade_type) : '—' }}</div>
            <div><span style="color: var(--text-muted);">Supplier:</span> {{ $workOrder->supplier?->name ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Reported by:</span> {{ ucfirst(str_replace('_', ' ', $workOrder->reported_by_type)) }}</div>
            @if($workOrder->reportedFaultReport)
                <div><span style="color: var(--text-muted);">From fault report:</span> <a href="{{ route('corex.rental-fault-reports.show', $workOrder->reported_fault_report_id) }}" class="underline">#{{ $workOrder->reported_fault_report_id }}</a></div>
            @endif
            <div><span style="color: var(--text-muted);">Reported at:</span> {{ $workOrder->reported_at?->format('Y-m-d H:i') }}</div>
            @if($workOrder->owner_approval_status !== \App\Models\RentalWorkOrder::APPROVAL_NOT_REQUIRED)
                <div><span style="color: var(--text-muted);">Owner approval:</span> {{ ucfirst($workOrder->owner_approval_status) }}</div>
            @endif
            @if($workOrder->ordered_at)
                <div><span style="color: var(--text-muted);">Ordered:</span> {{ $workOrder->ordered_at->format('Y-m-d') }}</div>
            @endif
            @if($workOrder->completed_at)
                <div><span style="color: var(--text-muted);">Completed:</span> {{ $workOrder->completed_at->format('Y-m-d') }}</div>
                <div><span style="color: var(--text-muted);">Paid by:</span> {{ ucfirst(str_replace('_', ' ', $workOrder->paid_by)) }}</div>
                @if($workOrder->cost_amount)
                    <div><span style="color: var(--text-muted);">Cost:</span> R{{ number_format((float) $workOrder->cost_amount, 2) }}</div>
                @endif
            @endif
        </div>

        @permission('rental_work_orders.create')
        @if($workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED)
        <div x-show="!editing" class="pt-1">
            <button type="button" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
        </div>
        <form x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-work-orders.update', $workOrder) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <div>
                <label class="text-xs font-medium">Title</label>
                <input type="text" name="title" required maxlength="191" value="{{ old('title', $workOrder->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Description</label>
                <textarea name="description" required rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('description', $workOrder->description) }}</textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
            </div>
        </form>
        @endif
        @endpermission

        @if($workOrder->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $workOrder->cancelled_at?->format('Y-m-d') }} by {{ $workOrder->cancelledByUser?->name }}: {{ $workOrder->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            @permission('rental_work_orders.cancel')
                @if($isOpen)
                    <button type="button" onclick="document.getElementById('cancel-work-order-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel work order</button>
                @endif
            @endpermission
            @permission('rental_work_orders.create')
                @if($workOrder->isDeletable() && $workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED)
                    <form method="POST" action="{{ route('corex.rental-work-orders.destroy', $workOrder) }}" onsubmit="return confirm('Archive this work order?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                @endif
            @endpermission
        </div>

        <form id="cancel-work-order-form" method="POST" action="{{ route('corex.rental-work-orders.cancel', $workOrder) }}" class="hidden space-y-2 pt-2">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>
    </div>

    @if($isOpen)
    {{-- §3.4a — only for a work order raised directly (no upstream fault
         report already satisfied this). --}}
    @if(!$workOrder->reported_fault_report_id)
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Owner approval</h2>
        @if($workOrder->approvals->isNotEmpty())
            <ul class="space-y-1 text-sm">
                @foreach($workOrder->approvals as $approval)
                    <li>{{ ucfirst($approval->decision) }} <span style="color: var(--text-muted);">({{ ucfirst(str_replace('_', ' ', $approval->evidence_type)) }}, {{ $approval->decided_at?->format('Y-m-d') }})</span></li>
                @endforeach
            </ul>
        @endif
        @permission('rental_work_orders.record_approval')
        <button type="button" onclick="document.getElementById('wo-approval-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Record decision</button>
        <form id="wo-approval-form" method="POST" action="{{ route('corex.rental-work-orders.approval.store', $workOrder) }}" class="hidden space-y-3 pt-2">
            @csrf
            <div>
                <label class="text-xs font-medium">Decision</label>
                <select name="decision" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="approved">Approved</option>
                    <option value="declined">Declined</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">Evidence</label>
                <select name="evidence_type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="whatsapp">WhatsApp reply</option>
                    <option value="email">Email</option>
                    <option value="verbal_note">Verbal (undocumented)</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">What the owner said</label>
                <textarea name="evidence_text" required rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save decision</button>
        </form>
        @endpermission
    </div>
    @endif

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Supplier</h2>
        @permission('rental_work_orders.create')
        <form method="POST" action="{{ route('corex.rental-work-orders.assign-supplier', $workOrder) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <div>
                <label class="text-xs">Supplier</label><br>
                <select name="agency_service_provider_id" required class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                    <option value="">Select…</option>
                    @foreach(\App\Models\DealV2\AgencyServiceProvider::active()->pickerOrder()->get() as $provider)
                        <option value="{{ $provider->id }}" @selected($workOrder->agency_service_provider_id === $provider->id)>{{ $provider->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="corex-btn-outline text-xs">{{ $workOrder->agency_service_provider_id ? 'Change supplier' : 'Assign supplier' }}</button>
        </form>
        @endpermission

        <div class="flex gap-2 pt-2">
            @permission('rental_work_orders.create')
                @if($workOrder->status === \App\Models\RentalWorkOrder::STATUS_ORDERED)
                    <form method="POST" action="{{ route('corex.rental-work-orders.start-progress', $workOrder) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs">Mark in progress</button>
                    </form>
                @endif
            @endpermission
            @permission('rental_work_orders.complete')
                <button type="button" onclick="document.getElementById('complete-work-order-form').classList.toggle('hidden')" class="corex-btn-primary text-xs">Complete</button>
            @endpermission
        </div>

        @permission('rental_work_orders.complete')
        <form id="complete-work-order-form" method="POST" action="{{ route('corex.rental-work-orders.complete', $workOrder) }}" enctype="multipart/form-data" class="hidden space-y-3 pt-2">
            @csrf
            <div>
                <label class="text-xs font-medium">Paid by</label>
                <select name="paid_by" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="owner">Owner</option>
                    <option value="tenant">Tenant</option>
                    <option value="deposit_deduction">Deposit deduction (label only — §5.1a)</option>
                    <option value="not_yet_paid">Not yet paid</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">Cost (R, optional)</label>
                <input type="number" name="cost_amount" step="0.01" min="0" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Completion notes</label>
                <textarea name="completion_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
            </div>
            @if($completionRequiresPhoto)
                <p class="text-xs" style="color: var(--text-muted);">A "completed" photo is required below before this can be saved. Your agency has this switched on in settings.</p>
            @else
                <p class="text-xs" style="color: var(--text-muted);">A "completed" photo below is optional — add one if it's useful evidence, but not every repair has a meaningful photo to take.</p>
            @endif
            <button type="submit" class="corex-btn-primary text-xs">Mark complete</button>
        </form>
        @endpermission
    </div>
    @endif

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Photos</h2>
        @if($workOrder->photos->isEmpty())
            <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
        @else
            <div class="grid grid-cols-4 gap-2">
                @foreach($workOrder->photos as $photo)
                    <div>
                        <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                        <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $photo->photo_type)) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
        @permission('rental_work_orders.create')
        <form method="POST" action="{{ route('corex.rental-work-orders.photos.store', $workOrder) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
            @csrf
            <select name="photo_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="reported">Reported (before)</option>
                <option value="in_progress">In progress</option>
                <option value="completed">Completed (after)</option>
            </select>
            <input type="file" name="photo" accept="image/*" required class="text-xs">
            <button type="submit" class="corex-btn-outline text-xs">Upload photo</button>
        </form>
        @endpermission
    </div>

    {{-- Johan, 2026-09-22 — "who did what": a plain chronological history,
         not a status badge on every row. RentalWorkOrder::history() merges
         creation, every logged update, and every approval decision into one
         timeline, oldest first. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">History</h2>
        <ul class="space-y-1 text-sm">
            @foreach($workOrder->history() as $entry)
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
        @permission('rental_work_orders.create')
        <form method="POST" action="{{ route('corex.rental-work-orders.notes.store', $workOrder) }}" class="flex items-end gap-2">
            @csrf
            <textarea name="note" required rows="1" placeholder="Add a note" class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs">Add</button>
        </form>
        @endpermission
    </div>
</div>
@endsection
