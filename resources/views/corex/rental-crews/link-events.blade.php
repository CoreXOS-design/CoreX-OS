@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14.29 — the full, paginated, newest-first log of
    ONE crew's standing link (every create / regenerate / email / revoke by the
    office, and every open and action by the crew). Read-only; append-only data.
--}}

@section('content')
@php
    $labels = [
        'issued' => 'Link created', 'regenerated' => 'Link regenerated', 'emailed' => 'Link emailed', 'revoked' => 'Link revoked',
        'opened' => 'Crew page opened', 'job_opened' => 'Job opened', 'action' => 'Crew action',
    ];
@endphp
<div class="p-6 max-w-4xl space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Crew link log — {{ $crew->name }}</h1>
        <a href="{{ route('corex.rental-crews.edit', $crew) }}" class="corex-btn-outline text-xs">&larr; Back to crew</a>
    </div>

    <form method="GET" action="{{ route('corex.rental-crews.link.events', $crew) }}" class="flex items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Show</label><br>
            <select name="event" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">Everything</option>
                @foreach($labels as $key => $label)
                    <option value="{{ $key }}" @selected($eventFilter === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium">When</th>
                    <th class="text-left px-4 py-2 font-medium">What</th>
                    <th class="text-left px-4 py-2 font-medium">Job</th>
                    <th class="text-left px-4 py-2 font-medium">Who / from</th>
                </tr>
            </thead>
            <tbody>
                @forelse($events as $ev)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $ev->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2"><strong>{{ $labels[$ev->event] ?? $ev->event }}</strong>@if($ev->note)<div class="text-xs" style="color: var(--text-muted);">{{ $ev->note }}</div>@endif</td>
                        <td class="px-4 py-2">
                            @if($ev->jobCard)
                                @permission('rental_job_cards.view')
                                    <a href="{{ route('corex.rental-job-cards.show', $ev->jobCard) }}" class="underline text-xs">{{ \Illuminate\Support\Str::limit($ev->jobCard->title, 40) }}</a>
                                @else
                                    <span class="text-xs">{{ \Illuminate\Support\Str::limit($ev->jobCard->title, 40) }}</span>
                                @endpermission
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs" style="color: var(--text-muted);">
                            @if($ev->actor){{ $ev->actor->name }}@elseif($ev->ip){{ $ev->ip }}@else — @endif
                            @if($ev->user_agent)<div title="{{ $ev->user_agent }}">{{ \Illuminate\Support\Str::limit($ev->user_agent, 50) }}</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                        @if($eventFilter)No entries of that kind.@else Nothing has happened on this link yet.@endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{ $events->links() }}
</div>
@endsection
