@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §3a — the fault report detail screen. --}}

@php
    $statusBadgeClass = match ($faultReport->status) {
        'resolved' => 'ds-badge-success',
        'reported', 'awaiting_approval' => 'ds-badge-info',
        'declined', 'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
@endphp

@section('content')
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $faultReport->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $faultReport->status)) }}</span>
            <span class="text-xs">
                @if($faultReport->property)
                    <a href="{{ route('corex.properties.show', $faultReport->property->id) }}" style="color:var(--brand-icon,#2563eb);">{{ $faultReport->property->buildDisplayAddress() }}</a>
                @else
                    <span style="color: var(--text-muted);">Unknown property</span>
                @endif
            </span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.rental-fault-reports.pdf', $faultReport) }}" target="_blank" class="corex-btn-outline text-xs">Download PDF</a>
            <a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-xs">&larr; All fault reports</a>
        </div>
    </div>

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
        <div class="grid grid-cols-2 gap-3 text-sm" x-show="!editing">
            <div class="col-span-2"><span style="color: var(--text-muted);">Description:</span> {{ $faultReport->description }}</div>
            <div><span style="color: var(--text-muted);">Tenancy:</span> {{ $faultReport->lease?->tenantNames() ?? 'None — vacancy period' }}</div>
            <div><span style="color: var(--text-muted);">Item:</span> {{ $faultReport->inspectionItem?->label ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Reported by:</span> {{ ucfirst(str_replace('_', ' ', $faultReport->reported_by_type)) }}{{ $faultReport->reportedByContact ? ' — ' . $faultReport->reportedByContact->first_name . ' ' . $faultReport->reportedByContact->last_name : ($faultReport->reportedByUser ? ' — ' . $faultReport->reportedByUser->name : '') }}</div>
            <div><span style="color: var(--text-muted);">Channel:</span> {{ ucfirst(str_replace('_', ' ', $faultReport->reported_channel)) }}</div>
            <div><span style="color: var(--text-muted);">Captured by:</span> {{ $faultReport->capturedByUser?->name ?? 'Self-reported' }}</div>
            <div><span style="color: var(--text-muted);">Reported at:</span> {{ $faultReport->reported_at?->format('Y-m-d H:i') }}</div>
            @if($faultReport->workOrder)
                <div><span style="color: var(--text-muted);">Work order:</span> <a href="{{ route('corex.rental-work-orders.show', $faultReport->rental_work_order_id) }}" class="underline">#{{ $faultReport->rental_work_order_id }}</a></div>
            @endif
            @if($faultReport->owner_approval_status !== \App\Models\RentalFaultReport::APPROVAL_NOT_REQUIRED)
                <div><span style="color: var(--text-muted);">Owner approval:</span> {{ ucfirst($faultReport->owner_approval_status) }}{{ $faultReport->approval_route ? ' — ' . str_replace('_', ' ', ucfirst($faultReport->approval_route)) : '' }}</div>
            @endif
            @if($faultReport->outcome)
                <div><span style="color: var(--text-muted);">Outcome:</span> {{ ucfirst(str_replace('_', ' ', $faultReport->outcome)) }}{{ $faultReport->repaired_at ? ' — ' . $faultReport->repaired_at->format('Y-m-d') : '' }}</div>
                @if($faultReport->outcome_note)
                    <div class="col-span-2"><span style="color: var(--text-muted);">Outcome note:</span> {{ $faultReport->outcome_note }}</div>
                @endif
            @endif
        </div>

        {{-- §3a schema — editable only while status='reported'; approval/outcome
             transitions are a separate action (Stage 2), not this form. --}}
        @permission('rental_fault_reports.create')
        @if($faultReport->status === \App\Models\RentalFaultReport::STATUS_REPORTED)
        <div x-show="!editing" class="pt-1">
            <button type="button" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
        </div>
        <form x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-fault-reports.update', $faultReport) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <div>
                <label class="text-xs font-medium">Title</label>
                <input type="text" name="title" required maxlength="191" value="{{ old('title', $faultReport->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Description</label>
                <textarea name="description" required rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('description', $faultReport->description) }}</textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
            </div>
        </form>
        @endif
        @endpermission

        @if($faultReport->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $faultReport->cancelled_at?->format('Y-m-d') }} by {{ $faultReport->cancelledByUser?->name }}: {{ $faultReport->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            @permission('rental_fault_reports.cancel')
                @if(!in_array($faultReport->status, ['cancelled', 'resolved'], true))
                    <button type="button" onclick="document.getElementById('cancel-fault-report-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel report</button>
                @endif
            @endpermission
            @permission('rental_fault_reports.create')
                @if($faultReport->isDeletable() && $faultReport->status === \App\Models\RentalFaultReport::STATUS_REPORTED)
                    <form method="POST" action="{{ route('corex.rental-fault-reports.destroy', $faultReport) }}" onsubmit="return confirm('Archive this fault report?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                @endif
            @endpermission
        </div>

        <form id="cancel-fault-report-form" method="POST" action="{{ route('corex.rental-fault-reports.cancel', $faultReport) }}" class="hidden space-y-2 pt-2">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>
    </div>

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Photos</h2>
        @if($faultReport->photos->isEmpty())
            <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
        @else
            <div class="grid grid-cols-4 gap-2">
                @foreach($faultReport->photos as $photo)
                    <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                @endforeach
            </div>
        @endif
        @permission('rental_fault_reports.create')
        <form method="POST" action="{{ route('corex.rental-fault-reports.photos.store', $faultReport) }}" enctype="multipart/form-data" class="flex items-end gap-2">
            @csrf
            <input type="file" name="photo" accept="image/*" required class="text-xs">
            <button type="submit" class="corex-btn-outline text-xs">Upload photo</button>
        </form>
        @endpermission
    </div>

    {{-- §3a.1/§3.4a — approval is always in writing; this is where that
         gets captured. Not shown once the report is closed — there is
         nothing left to decide. --}}
    @if(!in_array($faultReport->status, ['resolved', 'cancelled'], true))
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Owner approval</h2>

        @if($faultReport->approvals->isNotEmpty())
            <ul class="space-y-1 text-sm">
                @foreach($faultReport->approvals as $approval)
                    <li>
                        {{ ucfirst($approval->decision) }}{{ $approval->approval_route ? ' — ' . str_replace('_', ' ', ucfirst($approval->approval_route)) : '' }}
                        <span style="color: var(--text-muted);">({{ ucfirst(str_replace('_', ' ', $approval->evidence_type)) }}, {{ $approval->decided_at?->format('Y-m-d') }}, recorded by {{ $approval->recordedByUser?->name }})</span>
                        @if($approval->evidence_text)
                            <div class="text-xs" style="color: var(--text-muted);">{{ $approval->evidence_text }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- §3a.1/§0c — the agency_appoints route, once approved: raising the
             actual work order is a distinct, agency-timed decision, never
             automatic on approval alone. --}}
        @permission('rental_fault_reports.raise_work_order')
            @if($faultReport->status === \App\Models\RentalFaultReport::STATUS_APPROVED && $faultReport->approval_route === \App\Models\RentalFaultReport::ROUTE_AGENCY_APPOINTS)
                <button type="button" onclick="document.getElementById('raise-work-order-form').classList.toggle('hidden')" class="corex-btn-primary text-xs">Raise work order</button>
                <form id="raise-work-order-form" method="POST" action="{{ route('corex.rental-fault-reports.raise-work-order', $faultReport) }}" class="hidden space-y-3 pt-2">
                    @csrf
                    <div>
                        <label class="text-xs font-medium">Trade type</label>
                        <select name="trade_type" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                            <option value="">— Not yet known —</option>
                            @foreach(\App\Models\DealV2\AgencyServiceType::orderBy('label')->get() as $type)
                                <option value="{{ $type->code }}">{{ $type->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium">Title</label>
                        <input type="text" name="title" required maxlength="191" value="{{ $faultReport->title }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="text-xs font-medium">Description</label>
                        <textarea name="description" required rows="3" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ $faultReport->description }}</textarea>
                    </div>
                    <button type="submit" class="corex-btn-primary text-xs">Raise work order</button>
                </form>
            @endif
        @endpermission

        @permission('rental_fault_reports.record_approval')
            @if($faultReport->owner_approval_status === \App\Models\RentalFaultReport::APPROVAL_NOT_REQUIRED)
                <form method="POST" action="{{ route('corex.rental-fault-reports.request-approval', $faultReport) }}">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">Mark awaiting approval</button>
                </form>
            @endif
            <button type="button" onclick="document.getElementById('record-approval-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Record decision</button>
            <form id="record-approval-form" method="POST" action="{{ route('corex.rental-fault-reports.approval.store', $faultReport) }}" enctype="multipart/form-data" class="hidden space-y-3 pt-2" x-data="{ decision: 'approved' }">
                @csrf
                <div>
                    <label class="text-xs font-medium">Decision</label>
                    <select name="decision" x-model="decision" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <option value="approved">Approved</option>
                        <option value="declined">Declined</option>
                    </select>
                </div>
                <div x-show="decision === 'approved'" x-cloak>
                    <label class="text-xs font-medium">Who handles the repair</label>
                    <select name="approval_route" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <option value="agency_appoints">Agency appoints a contractor</option>
                        <option value="owner_handles">Owner sorts it themselves</option>
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
                <div>
                    <label class="text-xs font-medium">Screenshot (optional)</label>
                    <input type="file" name="evidence_file" accept="image/*" class="text-xs">
                </div>
                <button type="submit" class="corex-btn-primary text-xs">Save decision</button>
            </form>
        @endpermission
    </div>
    @endif

    {{-- §3a.2/§0c — the spine. Always reachable while the report is open,
         regardless of approval state. --}}
    @if(!in_array($faultReport->status, ['resolved', 'cancelled'], true))
    @permission('rental_fault_reports.resolve')
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Outcome</h2>
        <form method="POST" action="{{ route('corex.rental-fault-reports.outcome.store', $faultReport) }}" class="space-y-3" x-data="{ outcome: '' }">
            @csrf
            <div>
                <label class="text-xs font-medium">What happened</label>
                <select name="outcome" x-model="outcome" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">Select…</option>
                    <option value="repaired">Repaired</option>
                    <option value="repaired_partially">Repaired partially</option>
                    <option value="not_repaired">Not repaired</option>
                    <option value="owner_declined">Owner declined</option>
                    <option value="tenant_liable">Tenant liable</option>
                </select>
            </div>
            <div x-show="outcome === 'repaired' || outcome === 'repaired_partially'" x-cloak>
                <label class="text-xs font-medium">Date repaired</label>
                <input type="date" name="repaired_at" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div x-show="outcome !== '' && outcome !== 'repaired'" x-cloak>
                <label class="text-xs font-medium">Note</label>
                <textarea name="outcome_note" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save outcome</button>
        </form>
    </div>
    @endpermission
    @endif

    {{-- Johan, 2026-09-22 — "who did what": a plain chronological history,
         not a status badge on every row. RentalFaultReport::history() merges
         creation, every logged update, and every approval decision into one
         timeline, oldest first. Shown regardless of status — a resolved or
         cancelled report's history is exactly as real as an open one's. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">History</h2>
        <ul class="space-y-1 text-sm">
            @foreach($faultReport->history() as $entry)
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
@endsection
