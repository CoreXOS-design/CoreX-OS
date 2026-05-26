@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-lg font-bold" style="color:var(--text-primary);">Communications Log</h1>
    </div>

    {{-- Filters --}}
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        <select name="type" onchange="this.form.submit()" class="rounded-md text-sm px-3 py-1.5" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All Types</option>
            <option value="ppra_submission" {{ request('type') === 'ppra_submission' ? 'selected' : '' }}>PPRA Submission</option>
            <option value="seller_info_email" {{ request('type') === 'seller_info_email' ? 'selected' : '' }}>Seller Info Email</option>
            <option value="seller_info_whatsapp_link" {{ request('type') === 'seller_info_whatsapp_link' ? 'selected' : '' }}>WhatsApp Link</option>
        </select>
        <select name="status" onchange="this.form.submit()" class="rounded-md text-sm px-3 py-1.5" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All Statuses</option>
            <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent</option>
            <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
        </select>
    </form>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-raised); border-bottom:1px solid var(--border);">
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
                        'ppra_submission' => 'ds-badge-danger',
                        'seller_info_email' => 'ds-badge-info',
                        'seller_info_whatsapp_link' => 'ds-badge-success',
                        default => 'ds-badge-muted',
                    };
                    $typeLabel = match($log->email_type) {
                        'ppra_submission' => 'PPRA',
                        'seller_info_email' => 'Seller Email',
                        'seller_info_whatsapp_link' => 'WhatsApp',
                        default => $log->email_type,
                    };
                @endphp
                <tr class="border-t" style="border-color:var(--border);">
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-muted);">{{ $log->sent_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5"><span class="ds-badge {{ $typeBadge }}">{{ $typeLabel }}</span></td>
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-primary);">{{ Str::limit($log->subject, 50) }}</td>
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">{{ implode(', ', $log->recipients_to ?? []) }}</td>
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">{{ $log->sentBy?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5">
                        @if($log->status === 'sent')
                        <span class="text-xs font-bold" style="color:var(--ds-green);">Sent</span>
                        @else
                        <span class="text-xs font-bold" style="color:var(--ds-red);">Failed</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No communications yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $logs->withQueryString()->links() }}
</div>
@endsection
