@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div class="flex items-center justify-between gap-4" style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <div>
            <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">{{ $application->full_name }}</h2>
            <div class="flex items-center gap-2" style="font-size:0.875rem; color:rgba(255,255,255,0.55);">
                <span>{{ $application->designation_label }}</span>
                <span>&middot;</span>
                @php
                    $statusColors = [
                        'applied' => '#94a3b8', 'documents_pending' => '#f59e0b',
                        'compliance_review' => '#3b82f6', 'mentor_assignment' => '#8b5cf6',
                        'training' => '#14b8a6', 'activated' => '#22c55e',
                        'rejected' => '#ef4444', 'withdrawn' => '#6b7280',
                    ];
                    $sc = $statusColors[$application->status] ?? '#94a3b8';
                @endphp
                <span class="px-2 py-0.5 rounded text-xs font-bold" style="background:{{ $sc }}25; color:{{ $sc }};">{{ $application->status_label }}</span>
                <span>&middot;</span>
                <span>{{ $application->daysInCurrentStage() }} days in stage</span>
            </div>
        </div>
        <a href="{{ route('onboarding.index') }}" class="text-sm no-underline px-3 py-1.5 rounded-md" style="color:rgba(255,255,255,0.7); border:1px solid rgba(255,255,255,0.2);">Back to Pipeline</a>
    </div>

    {{-- Session messages --}}
    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- ══════════════════════════════════════
             LEFT — Application Details
             ══════════════════════════════════════ --}}
        <div class="space-y-4">
            {{-- Personal --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Personal</h3>
                <div class="space-y-2 text-sm">
                    <div><span style="color:var(--text-muted);">Name:</span> <span style="color:var(--text-primary); font-weight:600;">{{ $application->full_name }}</span></div>
                    <div><span style="color:var(--text-muted);">Email:</span> <span style="color:var(--text-primary);">{{ $application->email }}</span></div>
                    <div><span style="color:var(--text-muted);">Phone:</span> <span style="color:var(--text-primary);">{{ $application->phone ?? '—' }}</span></div>
                    <div><span style="color:var(--text-muted);">ID:</span> <span style="color:var(--text-primary);">{{ $application->id_number ?? '—' }}</span></div>
                </div>
            </div>

            {{-- Professional --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Professional</h3>
                <div class="space-y-2 text-sm">
                    <div><span style="color:var(--text-muted);">Designation:</span> <span style="color:var(--text-primary);">{{ $application->designation_label }}</span></div>
                    <div><span style="color:var(--text-muted);">Experience:</span> <span style="color:var(--text-primary);">{{ $application->years_experience }} years</span></div>
                    <div><span style="color:var(--text-muted);">Current agency:</span> <span style="color:var(--text-primary);">{{ $application->current_agency ?? '—' }}</span></div>
                    <div><span style="color:var(--text-muted);">FFC:</span> <span style="color:var(--text-primary);">{{ $application->ffc_number ?? '—' }}</span></div>
                    <div><span style="color:var(--text-muted);">FFC Expiry:</span> <span style="color:var(--text-primary);">{{ $application->ffc_expiry?->format('d M Y') ?? '—' }}</span></div>
                    <div><span style="color:var(--text-muted);">PPRA:</span> <span style="color:var(--text-primary);">{{ $application->ppra_status ?? '—' }}</span></div>
                </div>
            </div>

            {{-- Motivation & Referral --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Motivation & Referral</h3>
                <div class="space-y-2 text-sm">
                    @if($application->motivation)
                    <div style="color:var(--text-secondary);">{{ $application->motivation }}</div>
                    @endif
                    <div><span style="color:var(--text-muted);">Source:</span> <span style="color:var(--text-primary);">{{ $application->referral_source ?? '—' }}</span></div>
                    <div><span style="color:var(--text-muted);">Referred by:</span> <span style="color:var(--text-primary);">{{ $application->referredBy?->name ?? '—' }}</span></div>
                </div>
            </div>

            {{-- Timeline --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Timeline</h3>
                <div class="space-y-2 text-xs">
                    <div style="color:var(--text-secondary);">Applied: {{ $application->created_at->format('d M Y H:i') }}</div>
                    @if($application->status_changed_at && $application->status !== 'applied')
                    <div style="color:var(--text-secondary);">Status changed: {{ $application->status_changed_at->format('d M Y H:i') }}</div>
                    @endif
                    @if($application->reviewedByUser)
                    <div style="color:var(--text-secondary);">Reviewed by: {{ $application->reviewedByUser->name }}</div>
                    @endif
                    @if($application->activated_at)
                    <div style="color:#22c55e;">Activated: {{ $application->activated_at->format('d M Y H:i') }} by {{ $application->activatedByUser?->name }}</div>
                    @endif
                    @if($application->status_notes)
                    <div class="mt-2 p-2 rounded text-xs" style="background:var(--surface-2); color:var(--text-secondary);">{{ $application->status_notes }}</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════
             MIDDLE — Documents
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Documents</h3>

            {{-- Upload form --}}
            @if(!in_array($application->status, ['activated', 'rejected', 'withdrawn']))
            <form method="POST" action="{{ route('onboarding.upload', $application) }}" enctype="multipart/form-data"
                  class="mb-4 p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                @csrf
                <div class="grid grid-cols-1 gap-2">
                    <select name="document_type" required class="rounded-md px-2 py-1.5 text-xs"
                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        @foreach(\App\Models\ApplicationDocument::TYPE_LABELS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="file" name="file" required class="text-xs" style="color:var(--text-secondary);">
                    <button type="submit" class="corex-btn-primary text-xs px-3 py-1.5">Upload</button>
                </div>
            </form>
            @endif

            {{-- Document list --}}
            <div class="space-y-2">
                @forelse($application->documents as $doc)
                <div class="flex items-center gap-2 p-2 rounded-md" style="border:1px solid var(--border);">
                    @if($doc->status === 'verified')
                        <span class="text-green-500 flex-shrink-0"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg></span>
                    @elseif($doc->status === 'rejected')
                        <span class="text-red-500 flex-shrink-0"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg></span>
                    @else
                        <span class="flex-shrink-0" style="color:var(--text-muted);"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></span>
                    @endif
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-semibold truncate" style="color:var(--text-primary);">{{ $doc->type_label }}</div>
                        <div class="text-[10px] truncate" style="color:var(--text-muted);">{{ $doc->file_name }}</div>
                        @if($doc->rejection_reason)
                        <div class="text-[10px] mt-0.5" style="color:#ef4444;">{{ $doc->rejection_reason }}</div>
                        @endif
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <a href="{{ asset('storage/' . $doc->file_path) }}" target="_blank" class="text-[10px] px-1.5 py-0.5 rounded no-underline" style="color:var(--text-muted); border:1px solid var(--border);">View</a>
                        @if($doc->status === 'uploaded')
                        <form method="POST" action="{{ route('onboarding.verify-document', $doc) }}" class="inline">
                            @csrf
                            <input type="hidden" name="action" value="verify">
                            <button type="submit" class="text-[10px] px-1.5 py-0.5 rounded" style="background:rgba(34,197,94,0.12); color:#22c55e; border:1px solid rgba(34,197,94,0.25);">Verify</button>
                        </form>
                        <form method="POST" action="{{ route('onboarding.verify-document', $doc) }}" class="inline"
                              x-data onsubmit="event.preventDefault(); let r = prompt('Rejection reason:'); if(r) { this.querySelector('[name=rejection_reason]').value = r; this.submit(); }">
                            @csrf
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="rejection_reason" value="">
                            <button type="submit" class="text-[10px] px-1.5 py-0.5 rounded" style="background:rgba(239,68,68,0.12); color:#ef4444; border:1px solid rgba(239,68,68,0.25);">Reject</button>
                        </form>
                        @endif
                    </div>
                </div>
                @empty
                <div class="text-xs text-center py-4" style="color:var(--text-muted);">No documents uploaded yet.</div>
                @endforelse
            </div>
        </div>

        {{-- ══════════════════════════════════════
             RIGHT — Checklist
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Onboarding Checklist</h3>

            {{-- Progress bar --}}
            @php $pct = $application->completionPercent(); @endphp
            <div class="flex items-center gap-2 mb-4">
                <div class="flex-1 h-2 rounded-full overflow-hidden" style="background:var(--border);">
                    <div class="h-full rounded-full transition-all" style="width:{{ $pct }}%; background:{{ $pct === 100 ? '#22c55e' : '#0ea5e9' }};"></div>
                </div>
                <span class="text-xs font-bold" style="color:var(--text-primary);">{{ $pct }}%</span>
            </div>

            <div class="space-y-1">
                @foreach($application->checklist as $item)
                <form method="POST" action="{{ route('onboarding.toggle-checklist', $item) }}" class="flex items-start gap-2 p-1.5 rounded transition-colors hover:bg-white/5">
                    @csrf
                    <button type="submit" class="flex-shrink-0 mt-0.5 w-4 h-4 rounded border flex items-center justify-center transition-colors"
                            style="{{ $item->is_completed ? 'background:#22c55e; border-color:#22c55e; color:#fff;' : 'background:var(--surface); border-color:var(--border);' }}">
                        @if($item->is_completed)
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        @endif
                    </button>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs {{ $item->is_completed ? 'line-through' : '' }}" style="color:{{ $item->is_completed ? 'var(--text-muted)' : 'var(--text-primary)' }};">
                            {{ $item->item_label }}
                            @if($item->is_required)
                            <span class="text-red-400">*</span>
                            @endif
                        </div>
                        @if($item->is_completed && $item->completed_at)
                        <div class="text-[10px]" style="color:var(--text-muted);">
                            {{ $item->completed_at->format('d M H:i') }}
                            @if($item->completedByUser) by {{ $item->completedByUser->name }} @endif
                            @if($item->notes) — {{ $item->notes }} @endif
                        </div>
                        @endif
                    </div>
                </form>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         STATUS ACTIONS
         ══════════════════════════════════════ --}}
    @if(!in_array($application->status, ['activated', 'rejected', 'withdrawn']))
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Actions</h3>
        <div class="flex items-center gap-3 flex-wrap">
            {{-- Advance --}}
            @php $next = $application->nextStatus(); @endphp
            @if($next && $next !== 'activated')
            <form method="POST" action="{{ route('onboarding.status', $application) }}"
                  onsubmit="return confirm('Advance to {{ \App\Models\AgentApplication::STATUS_LABELS[$next] }}?')">
                @csrf
                <input type="hidden" name="status" value="{{ $next }}">
                <button type="submit" class="corex-btn-primary text-sm px-4 py-2"
                        {{ !$application->canAdvanceTo($next) ? 'disabled style=opacity:0.5;cursor:not-allowed' : '' }}>
                    Advance to {{ \App\Models\AgentApplication::STATUS_LABELS[$next] }}
                </button>
            </form>
            @endif

            {{-- Activate --}}
            @if($next === 'activated' || $application->status === 'training')
            <form method="POST" action="{{ route('onboarding.activate', $application) }}"
                  onsubmit="return confirm('Activate this agent? This will create their user account.')"
                  x-data>
                @csrf
                <div class="flex items-center gap-2">
                    <select name="branch_id" class="rounded-md px-2 py-1.5 text-xs"
                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <option value="">No branch</option>
                        @foreach(\App\Models\Branch::orderBy('name')->get(['id','name']) as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="text-sm px-4 py-2 rounded-md font-semibold"
                            style="background:#22c55e; color:#fff;"
                            {{ !$application->canAdvanceTo('activated') ? 'disabled style=opacity:0.5;cursor:not-allowed;background:#22c55e;color:#fff' : '' }}>
                        Activate Agent
                    </button>
                </div>
            </form>
            @endif

            {{-- Reject --}}
            <form method="POST" action="{{ route('onboarding.status', $application) }}"
                  x-data onsubmit="event.preventDefault(); let r = prompt('Reason for rejection:'); if(r) { this.querySelector('[name=status_notes]').value = r; this.submit(); }">
                @csrf
                <input type="hidden" name="status" value="rejected">
                <input type="hidden" name="status_notes" value="">
                <button type="submit" class="text-sm px-4 py-2 rounded-md font-medium" style="color:#ef4444; border:1px solid rgba(239,68,68,0.3);">
                    Reject
                </button>
            </form>
        </div>
    </div>
    @endif

</div>
@endsection
