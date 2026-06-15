{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $hasFilters = request()->filled('type') || request()->filled('status');
@endphp
<div class="w-full space-y-5">

    {{-- Page header (branded — §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Communications Log</h1>
                <p class="text-sm text-white/60">Every PPRA submission, seller info email, and WhatsApp link sent from CoreX.</p>
            </div>
        </div>
    </div>

    {{-- Filter bar (§3.8) --}}
    <div class="rounded-md px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" class="flex items-center gap-3 flex-wrap">
            <select name="type" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All Types</option>
                <option value="ppra_submission" {{ request('type') === 'ppra_submission' ? 'selected' : '' }}>PPRA Submission</option>
                <option value="seller_info_email" {{ request('type') === 'seller_info_email' ? 'selected' : '' }}>Seller Info Email</option>
                <option value="seller_info_whatsapp_link" {{ request('type') === 'seller_info_whatsapp_link' ? 'selected' : '' }}>WhatsApp Link</option>
            </select>
            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All Statuses</option>
                <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
            @if($hasFilters)
            <a href="{{ route('compliance.communications.index') }}" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">Clear</a>
            @endif
            <span class="ml-auto text-xs" style="color:var(--text-muted);">
                Showing {{ number_format($logs->count()) }} of {{ number_format($logs->total()) }}
            </span>
        </form>
    </div>

    {{-- Table (§3.7) --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Date</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Subject</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">To</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Sent By</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                    @php
                        $typeBadge = match($log->email_type) {
                            'ppra_submission' => 'ds-badge-info',
                            'seller_info_email' => 'ds-badge-info',
                            'seller_info_whatsapp_link' => 'ds-badge-success',
                            default => 'ds-badge-default',
                        };
                        $typeLabel = match($log->email_type) {
                            'ppra_submission' => 'PPRA',
                            'seller_info_email' => 'Seller Email',
                            'seller_info_whatsapp_link' => 'WhatsApp',
                            default => $log->email_type,
                        };
                    @endphp
                    <tr>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-muted);">{{ $log->sent_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $typeBadge }}">{{ $typeLabel }}</span></td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ Str::limit($log->subject, 50) }}</td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ implode(', ', $log->recipients_to ?? []) }}</td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $log->sentBy?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if($log->status === 'sent')
                            <span class="ds-badge ds-badge-success">Sent</span>
                            @else
                            <span class="ds-badge ds-badge-danger">Failed</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No communications yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
        <div class="px-4 py-3" style="border-top:1px solid var(--border);">
            {{ $logs->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
