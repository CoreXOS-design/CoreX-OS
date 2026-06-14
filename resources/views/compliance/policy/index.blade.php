@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agency Policies</h1>
                <p class="text-sm text-white/60">Versioned staff-acknowledged policies (POPIA / CPA / NCC and more).</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('compliance.policy.dashboard.index') }}" class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3);">Register</a>
                @permission('edit_policy')
                <a href="{{ route('compliance.policy.create') }}" class="corex-btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Policy
                </a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    @forelse($policies as $policy)
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <div>
                <h2 class="text-base font-bold" style="color: var(--text-primary);">{{ $policy->name }}</h2>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);"><code>{{ $policy->policy_key }}</code> @if($policy->description) — {{ $policy->description }} @endif</p>
            </div>
            @permission('edit_policy')
            <a href="{{ route('compliance.policy.version.create', $policy) }}" class="corex-btn-outline">New Version</a>
            @endpermission
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Version</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Approved</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Effective</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Next Review</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($policy->versions as $v)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">v{{ $v->version_number }}</td>
                        <td class="px-4 py-3">
                            @if($v->status === 'active')
                                <span class="ds-badge ds-badge-success">Active</span>
                            @elseif($v->status === 'draft')
                                <span class="ds-badge ds-badge-warning">Draft</span>
                            @else
                                <span class="ds-badge ds-badge-default">Superseded</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $v->approved_at?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $v->effective_from?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $v->next_review_due?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('compliance.policy.show', $v) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">View</a>
                                @if($v->canBeEdited())
                                    @permission('edit_policy')
                                    <a href="{{ route('compliance.policy.edit', $v) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                                    @endpermission
                                    @permission('approve_policy')
                                    <a href="{{ route('compliance.policy.approve.form', $v) }}" class="text-xs font-semibold" style="color: var(--ds-amber);">Approve</a>
                                    @endpermission
                                @endif
                                <a href="{{ route('compliance.policy.pdf', $v) }}" class="text-xs font-semibold" style="color: var(--text-muted);" target="_blank">PDF</a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-sm" style="color: var(--text-muted);">No versions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @empty
    <div class="rounded-md px-4 py-12 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No policies yet</h3>
        <p class="text-sm mb-3" style="color: var(--text-muted);">Create your first agency policy to start collecting staff sign-off.</p>
        @permission('edit_policy')
        <a href="{{ route('compliance.policy.create') }}" class="corex-btn-primary">New Policy</a>
        @endpermission
    </div>
    @endforelse
</div>
@endsection
