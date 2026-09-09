{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Outbound Mail Captures — CoreX only</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Every message intercepted while outbound mail interception was on. View and export only —
                    nothing here is re-sent automatically. To re-send something, use the app's own action for it
                    (e.g. Resend on an e-sign request) once interception is off.
                </p>
            </div>
            <span class="ds-badge ds-badge-default">{{ number_format($totalCount) }} total</span>
        </div>
    </div>

    @if($captures->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
            <p class="text-sm" style="color: var(--text-muted, #6b7280);">Nothing has ever been captured.</p>
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
            <table class="w-full text-xs">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th class="text-left px-4 py-2" style="color: var(--text-muted);">Captured</th>
                        <th class="text-left px-4 py-2" style="color: var(--text-muted);">Environment</th>
                        <th class="text-left px-4 py-2" style="color: var(--text-muted);">To</th>
                        <th class="text-left px-4 py-2" style="color: var(--text-muted);">Subject</th>
                        <th class="text-left px-4 py-2" style="color: var(--text-muted);">Forwarded to sink</th>
                        <th class="text-right px-4 py-2" style="color: var(--text-muted);"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($captures as $capture)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $capture->captured_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $capture->environment }}</td>
                            <td class="px-4 py-2" style="color: var(--text-primary);">{{ \Illuminate\Support\Str::limit($capture->to_addresses, 60) }}</td>
                            <td class="px-4 py-2" style="color: var(--text-primary);">{{ \Illuminate\Support\Str::limit($capture->subject ?? '(no subject)', 60) }}</td>
                            <td class="px-4 py-2">
                                <span class="ds-badge {{ $capture->forwarded_to_sink ? 'ds-badge-success' : 'ds-badge-default' }}">
                                    {{ $capture->forwarded_to_sink ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('admin.outbound-mail-captures.download', $capture) }}" style="color: var(--brand-icon, #2563eb);">Download .eml</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $captures->links() }}</div>
    @endif
</div>
@endsection
