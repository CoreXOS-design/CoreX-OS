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
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">{{ $errors->first() }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</span>
            <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', '-', $inspection->type)) }} inspection</span>
        </div>
        <a href="{{ route('corex.rental-inspections.index') }}" class="corex-btn-outline text-xs">&larr; All inspections</a>
    </div>

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div><span style="color: var(--text-muted);">Tenant(s):</span> {{ $inspection->lease?->tenantNames() ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Recorded by:</span> {{ $inspection->createdBy?->name ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Scheduled:</span> {{ $inspection->scheduled_for?->format('Y-m-d') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Completed:</span> {{ $inspection->completed_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Fault-report deadline:</span> {{ $inspection->fault_report_deadline_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Signing deadline:</span> {{ $inspection->signing_deadline_at?->format('Y-m-d H:i') ?? '—' }}</div>
        </div>

        @if($inspection->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $inspection->cancelled_at?->format('Y-m-d') }} by {{ $inspection->cancelledBy?->name }}: {{ $inspection->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            @permission('rental_inspections.create')
                @if(!in_array($inspection->status, ['completed', 'cancelled'], true))
                    <button type="button" onclick="document.getElementById('cancel-inspection-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel inspection</button>
                @endif
                @if($inspection->isDeletable() && $inspection->status !== 'completed')
                    <form method="POST" action="{{ route('corex.rental-inspections.destroy', $inspection) }}" onsubmit="return confirm('Archive this inspection?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                @endif
                @if($inspection->trashed())
                    <form method="POST" action="{{ route('corex.rental-inspections.restore', $inspection->id) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                    </form>
                @endif
            @endpermission
        </div>

        <form id="cancel-inspection-form" method="POST" action="{{ route('corex.rental-inspections.cancel', $inspection) }}" class="hidden space-y-2 pt-2">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>
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
        @foreach($inspection->signatures as $signature)
            @php
                $partyLabel = match($signature->party_role) {
                    'agent' => 'Agent',
                    'landlord' => 'Landlord' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                    default => 'Tenant' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                };
                $reasonLabel = collect($refusalReasonPresets)->firstWhere('key', $signature->refusal_reason_preset)['label']
                    ?? $signature->refusal_reason_preset;
            @endphp
            <div class="text-sm py-2" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span style="color: var(--text-primary);">{{ $partyLabel }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $signature->disposition_recorded_at?->format('Y-m-d H:i') }}</span>
                </div>
                @if($signature->disposition === 'signed')
                    <div class="mt-1.5">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Signed</span>
                        @if($signature->party_signature_path)
                            <div class="mt-1">
                                <img src="{{ $signature->party_signature_path }}" alt="{{ $partyLabel }}'s signature"
                                     style="max-height: 60px; background: #fff; border: 1px solid var(--border); border-radius: 4px; padding: 4px;">
                            </div>
                        @endif
                    </div>
                @else
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Refused to sign</span>
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
</div>
@endsection
