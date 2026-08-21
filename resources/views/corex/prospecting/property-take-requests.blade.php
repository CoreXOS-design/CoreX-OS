{{-- Deeds-capture duplicate-match take rule (Johan, 2026-08-21) — BM/admin approval queue
     for a match in the no-go/auto-take approval band. Nothing is promoted or reassigned
     until decided here. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="width:100%; max-width:1100px; margin:0 auto;">
    <div style="margin-bottom:1rem;">
        <h1 class="text-2xl font-bold" style="color:var(--text-primary);">Duplicate-property take requests</h1>
        <p class="text-sm" style="color:var(--text-muted);">
            A deeds capture matched a property that's been off the market long enough to need your
            sign-off before an agent can take it. Approving moves it to Prospecting under the
            requesting agent; rejecting leaves it exactly as it is.
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-3 py-2 mb-4 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-3 py-2 mb-4 text-sm" style="background:#fef2f2; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="min-w-full text-sm ds-table">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Property</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Requested by</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Off market</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Decision</th>
                </tr>
            </thead>
            <tbody>
            @forelse($requests as $r)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-3">
                        <div class="font-medium" style="color:var(--text-primary);">{{ $r->property->address ?? 'Property #' . $r->property_id }}</div>
                        <div class="text-xs" style="color:var(--text-muted);">Status: {{ $r->matched_property_status }}</div>
                    </td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $r->requestedBy->name ?? 'Unknown' }}</td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded-full font-bold"
                              style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 15%, transparent); color:var(--ds-amber,#f59e0b);">
                            {{ $r->age_days }} day{{ $r->age_days === 1 ? '' : 's' }}
                        </span>
                        <div class="text-[11px] mt-0.5" style="color:var(--text-muted);">
                            {{ str_replace('_', ' ', $r->date_field_used) }}{{ $r->date_is_fallback ? ' — estimated' : '' }}
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-2">
                            <form method="POST" action="{{ route('corex.property-take-requests.approve', $r->id) }}" class="inline"
                                  onsubmit="return confirm('Approve — {{ $r->requestedBy->name ?? 'this agent' }} takes this property?');">
                                @csrf
                                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--ds-green,#059669);">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('corex.property-take-requests.reject', $r->id) }}" class="inline"
                                  onsubmit="return confirm('Reject this request?');">
                                @csrf
                                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:transparent; color:#ef4444; border:1px solid rgba(239,68,68,0.4);">Reject</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">Nothing pending.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
