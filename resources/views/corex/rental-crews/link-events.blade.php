@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14.29 — the full, paginated, newest-first log of
    ONE crew's standing link (every create / regenerate / email / revoke by the
    office, and every open and action by the crew). Read-only; append-only data.
--}}

@section('corex-content')
@php
    $labels = [
        'issued' => 'Link created', 'regenerated' => 'Link regenerated', 'emailed' => 'Link emailed', 'revoked' => 'Link revoked',
        'opened' => 'Crew page opened', 'job_opened' => 'Job opened', 'action' => 'Crew action',
    ];
@endphp
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Crew link log — {{ $crew->name }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Everything that has happened on this crew member's standing link: created, emailed or revoked by the office, and opened or used by the crew.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.rental-crews.edit', $crew) }}" class="corex-btn-outline text-xs">&larr; Back to crew</a>
            </div>
        </div>
    </div>

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-crews.link.events', $crew) }}" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Show</label>
            <select name="event" class="list-header-filter text-sm {{ filled($eventFilter) ? 'is-active' : '' }}">
                <option value="">Everything</option>
                @foreach($labels as $key => $label)
                    <option value="{{ $key }}" @selected($eventFilter === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
            <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($events->total()) }} {{ \Illuminate\Support\Str::plural('entry', $events->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">When</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">What</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Job</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Who / from</th>
                </tr>
            </thead>
            <tbody>
                @forelse($events as $ev)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                        <td class="px-4 py-3 whitespace-nowrap">{{ $ev->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3"><strong>{{ $labels[$ev->event] ?? $ev->event }}</strong>@if($ev->note)<div class="text-xs" style="color: var(--text-muted);">{{ $ev->note }}</div>@endif</td>
                        <td class="px-4 py-3">
                            @if($ev->jobCard)
                                @permission('rental_job_cards.view')
                                    @feature('rental-job-cards')<a href="{{ route('corex.rental-job-cards.show', $ev->jobCard) }}" class="underline text-xs">{{ \Illuminate\Support\Str::limit($ev->jobCard->title, 40) }}</a>@else {{ \Illuminate\Support\Str::limit($ev->jobCard->title, 40) }} @endfeature
                                @else
                                    <span class="text-xs">{{ \Illuminate\Support\Str::limit($ev->jobCard->title, 40) }}</span>
                                @endpermission
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                            @if($ev->actor){{ $ev->actor->name }}@elseif($ev->ip){{ $ev->ip }}@else — @endif
                            @if($ev->user_agent)<div title="{{ $ev->user_agent }}">{{ \Illuminate\Support\Str::limit($ev->user_agent, 50) }}</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
                        @if($eventFilter)No entries of that kind.@else Nothing has happened on this link yet.@endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($events->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $events->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
