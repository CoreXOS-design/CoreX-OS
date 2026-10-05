{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — start an agency timeline (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
@php $nice = fn ($t) => preg_replace('/\\{\\{\\s*agency_name\\s*\\}\\}/', $agency->name, $t); @endphp
<div class="w-full max-w-3xl space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Start timeline — {{ $agency->name }}</h1>
        <p class="text-xs" style="color: var(--text-muted);">Every default step below gets a real date counted from the start date. You can change any date afterwards, and add custom steps.</p>
    </div>
    @include('admin.partials.platform-flash')

    <form method="GET" class="flex items-end gap-3 rounded-md p-4" style="background: var(--surface); border:1px solid var(--border);">
        <div>
            <label class="ds-label block mb-1">Start date <span style="color:var(--text-muted);">(defaults to the agency's creation date)</span></label>
            <input type="date" name="start_date" value="{{ $start->toDateString() }}" class="ds-field">
        </div>
        <button class="corex-btn-outline text-xs" type="submit">Preview dates</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm ds-table">
            <thead><tr style="background: var(--surface-2); color:var(--text-muted);" class="text-left text-xs uppercase tracking-wider"><th class="px-4 py-3">Step</th><th class="px-4 py-3">Date</th></tr></thead>
            <tbody>
            @forelse($preview as $p)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5" style="color:var(--text-primary);">{{ $nice($p['title']) }} @if($p['live'])<span class="ds-badge ds-badge-success ml-1">Go live</span>@endif</td>
                    <td class="px-4 py-2.5 tabular-nums" style="color:var(--text-secondary);">{{ $p['date']->format('D j M Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="px-4 py-8 text-center" style="color:var(--text-muted);">There are no default steps yet — add some in <a class="underline" href="{{ route('admin.timeline-defaults.index') }}">Dev Settings → Agency timeline defaults</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <form method="POST" action="{{ route('admin.agency-timelines.start', $agency) }}" class="flex items-center gap-3">
        @csrf
        <input type="hidden" name="start_date" value="{{ $start->toDateString() }}">
        <button class="corex-btn-primary text-sm" type="submit">Start timeline on {{ $start->format('j M Y') }}</button>
        <a href="{{ route('admin.agency-timelines.index') }}" class="text-xs underline" style="color:var(--text-muted);">Cancel</a>
    </form>
</div>
@endsection
