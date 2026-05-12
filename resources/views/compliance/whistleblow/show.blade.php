@extends('layouts.corex')

@section('corex-content')
@php
    $tierLabels = ['tier_1' => 'Tier 1 — Paperwork Breach', 'tier_2' => 'Tier 2 — No FFC Displayed', 'tier_3' => 'Tier 3 — Unregistered Practitioner'];
    $tierBadges = ['tier_1' => 'ds-badge-warning', 'tier_2' => 'ds-badge-info', 'tier_3' => 'ds-badge-danger'];
    $statusBadges = [
        'draft' => 'ds-badge-muted', 'pending_approval' => 'ds-badge-warning',
        'changes_requested' => 'ds-badge-info', 'rejected' => 'ds-badge-danger',
        'approved' => 'ds-badge-success', 'sent' => 'ds-badge-success',
        'acknowledged_by_ppra' => 'ds-badge-brand', 'closed' => 'ds-badge-muted',
    ];
@endphp
<div class="w-full space-y-4" x-data="{ showAudit: false, rejectOpen: false, changesOpen: false }">

    {{-- Back + header --}}
    <div class="flex items-center gap-4 flex-wrap">
        <a href="{{ route('compliance.whistleblow.index') }}" class="inline-flex items-center gap-1.5 text-sm no-underline" style="color:var(--text-secondary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            Back to Queue
        </a>
    </div>

    @if(session('success'))
    <div class="rounded-md p-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-green) 10%, transparent); color:var(--ds-green);">
        {{ session('success') }}
    </div>
    @endif

    {{-- Header card --}}
    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex items-center gap-3 flex-wrap">
            <span class="font-mono text-lg font-bold" style="color:var(--text-primary);">HFC-WB-{{ $complaint->id }}</span>
            <span class="ds-badge {{ $tierBadges[$complaint->tier] ?? '' }}">{{ $tierLabels[$complaint->tier] ?? $complaint->tier }}</span>
            <span class="ds-badge {{ $statusBadges[$complaint->status] ?? '' }}">{{ str_replace('_', ' ', ucfirst($complaint->status)) }}</span>
            <span class="text-xs" style="color:var(--text-muted);">{{ $complaint->created_at->diffInDays(now()) }} days open</span>
        </div>
    </div>

    {{-- Property --}}
    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Property</h3>
        <p class="text-sm" style="color:var(--text-primary);">{{ $complaint->property_address }}</p>
    </div>

    {{-- Subjects --}}
    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Subjects of Complaint ({{ $complaint->subjects->count() }})</h3>
        @foreach($complaint->subjects as $subj)
        <div class="py-2 {{ !$loop->last ? 'border-b' : '' }}" style="border-color:var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $subj->agency_name }}</div>
            @if($subj->practitioner_name)
            <div class="text-xs" style="color:var(--text-secondary);">Practitioner: {{ $subj->practitioner_name }}</div>
            @endif
            <a href="{{ $subj->portal_url }}" target="_blank" class="text-xs no-underline" style="color:var(--brand-default);">{{ Str::limit($subj->portal_url, 60) }}</a>
            <span class="text-xs ml-2" style="color:var(--text-muted);">{{ strtoupper($subj->portal_source) }}</span>
        </div>
        @endforeach
    </div>

    {{-- Reporter --}}
    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Reporter</h3>
        <p class="text-sm" style="color:var(--text-primary);">{{ $complaint->reporter?->name ?? '—' }} &middot; {{ $complaint->created_at->format('d M Y H:i') }}</p>
    </div>

    {{-- Tier 1: Seller statement --}}
    @if($complaint->tier === 'tier_1' && $complaint->seller_statement)
    <div class="rounded-md p-5" style="background:color-mix(in srgb, var(--ds-amber) 5%, var(--surface)); border:1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Seller Statement</h3>
        @if($complaint->seller_consents_to_named_complaint)
        <p class="text-xs mb-2" style="color:var(--ds-green);">Seller consents to being named</p>
        @endif
        <p class="text-sm italic" style="color:var(--text-primary);">"{{ $complaint->seller_statement }}"</p>
    </div>
    @endif

    {{-- Evidence --}}
    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Evidence ({{ $complaint->evidence->count() }})</h3>
        @forelse($complaint->evidence as $ev)
        <div class="flex items-center gap-3 py-2 {{ !$loop->last ? 'border-b' : '' }}" style="border-color:var(--border);">
            <span class="ds-badge ds-badge-muted text-[0.625rem]">{{ str_replace('_', ' ', $ev->evidence_type) }}</span>
            <span class="text-sm" style="color:var(--text-primary);">{{ $ev->description ?? $ev->original_filename ?? 'Evidence' }}</span>
            @if($ev->original_filename)
            <span class="text-xs" style="color:var(--text-muted);">{{ $ev->original_filename }}</span>
            @endif
            <span class="text-xs ml-auto" style="color:var(--text-muted);">{{ $ev->created_at->format('d M H:i') }}</span>
        </div>
        @empty
        <p class="text-sm" style="color:var(--text-muted);">No evidence attached.</p>
        @endforelse
    </div>

    {{-- Notes --}}
    @if($complaint->agent_notes)
    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Agent Notes</h3>
        <p class="text-sm" style="color:var(--text-primary);">{{ $complaint->agent_notes }}</p>
    </div>
    @endif

    {{-- Audit timeline (collapsed) --}}
    <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
        <button type="button" @click="showAudit = !showAudit" class="w-full p-5 flex items-center justify-between text-left">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Audit Timeline ({{ $auditLog->count() }})</h3>
            <svg class="w-4 h-4 transition-transform" :class="showAudit && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <div x-show="showAudit" x-cloak class="px-5 pb-5 space-y-1">
            @foreach($auditLog as $entry)
            <div class="flex items-center gap-3 text-xs py-1.5 {{ !$loop->last ? 'border-b' : '' }}" style="border-color:var(--border);">
                <span class="flex-shrink-0 w-28" style="color:var(--text-muted);">{{ $entry->created_at->format('d M H:i') }}</span>
                <span class="flex-shrink-0 w-28 font-medium" style="color:var(--text-primary);">{{ $entry->user?->name ?? 'System' }}</span>
                <span style="color:var(--text-secondary);">{{ str_replace('_', ' ', ucfirst($entry->action)) }}</span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- PDF download --}}
    @if($complaint->complaint_pdf_path && file_exists($complaint->complaint_pdf_path))
    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Generated PDF</h3>
        <p class="text-sm" style="color:var(--text-primary);">HFC-WB-{{ $complaint->id }}.pdf ({{ number_format(filesize($complaint->complaint_pdf_path) / 1024, 1) }} KB)</p>
    </div>
    @endif

    {{-- Action footer — only for pending_approval + approver --}}
    @if($complaint->status === 'pending_approval' && $isApprover)
    <div class="rounded-md p-5 flex items-center gap-3 flex-wrap" style="background:var(--surface); border:1px solid var(--border);">
        <form method="POST" action="{{ route('compliance.whistleblow.approve', $complaint) }}" onsubmit="return confirm('Send this complaint to PPRA now?')">
            @csrf
            <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--ds-green);">
                Approve & Send to PPRA
            </button>
        </form>

        <button type="button" @click="changesOpen = true" class="px-4 py-2 rounded-md text-sm font-semibold" style="background:var(--surface-raised); border:1px solid var(--border); color:var(--text-primary);">
            Request Changes
        </button>

        <button type="button" @click="rejectOpen = true" class="px-4 py-2 rounded-md text-sm font-semibold" style="color:var(--ds-red); background:color-mix(in srgb, var(--ds-red) 10%, transparent);">
            Reject
        </button>
    </div>

    {{-- Reject modal --}}
    <template x-teleport="body">
    <div x-show="rejectOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" x-transition.opacity>
        <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="rejectOpen = false"></div>
        <div class="relative rounded-md shadow-2xl p-5" style="width:420px; max-width:95vw; background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-base font-bold mb-3" style="color:var(--text-primary);">Reject Complaint</h3>
            <form method="POST" action="{{ route('compliance.whistleblow.reject', $complaint) }}">
                @csrf
                <textarea name="reason" required rows="3" class="w-full rounded-md text-sm px-3 py-2 mb-3" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="Reason for rejection..."></textarea>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="rejectOpen = false" class="px-4 py-2 text-sm" style="color:var(--text-secondary);">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--ds-red);">Reject</button>
                </div>
            </form>
        </div>
    </div>
    </template>

    {{-- Request changes modal --}}
    <template x-teleport="body">
    <div x-show="changesOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" x-transition.opacity>
        <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="changesOpen = false"></div>
        <div class="relative rounded-md shadow-2xl p-5" style="width:420px; max-width:95vw; background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-base font-bold mb-3" style="color:var(--text-primary);">Request Changes</h3>
            <form method="POST" action="{{ route('compliance.whistleblow.request-changes', $complaint) }}">
                @csrf
                <textarea name="notes" required rows="3" class="w-full rounded-md text-sm px-3 py-2 mb-3" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="What needs to be changed..."></textarea>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="changesOpen = false" class="px-4 py-2 text-sm" style="color:var(--text-secondary);">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-default);">Send Back</button>
                </div>
            </form>
        </div>
    </div>
    </template>
    @endif

</div>
@endsection
