@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — Campaigns → Suppressed (.ai/specs/campaigns.md §7.4). Lift = archive, Suppress again = restore. --}}
<div class="w-full h-full flex flex-col" x-data="{ adding: {{ $errors->has('email') ? 'true' : 'false' }} }">
    <div class="rounded-md px-6 py-3.5 corex-page-banner flex-shrink-0 flex items-center justify-between gap-3">
        <h1 class="text-base font-bold" style="color: var(--text-primary);">Suppressed</h1>
        <button type="button" @click="adding = !adding" class="corex-btn-primary text-xs">Add address</button>
    </div>

    @include('admin.campaigns._head')

    <form x-show="adding" x-cloak method="POST" action="{{ route('admin.campaigns.suppressions.store') }}" class="cmp-panel p-3 mt-2 flex-shrink-0 grid grid-cols-1 md:grid-cols-5 gap-2 items-end max-w-3xl" data-add-suppression>
        @csrf
        <div class="md:col-span-2"><label class="cmp-label">Email</label><input type="email" name="email" value="{{ old('email') }}" required class="cmp-input"></div>
        <div class="md:col-span-2"><label class="cmp-label">Note</label><input name="note" value="{{ old('note') }}" maxlength="255" class="cmp-input"></div>
        <button class="corex-btn-primary text-xs justify-center">Suppress</button>
    </form>

    <form method="GET" class="cmp-panel p-3 mt-2 flex-shrink-0 grid grid-cols-2 md:grid-cols-7 gap-2 items-end">
        <div class="col-span-2"><label class="cmp-label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Email" class="cmp-input"></div>
        <div><label class="cmp-label">Reason</label>
            <select name="reason" class="cmp-input"><option value="">All</option>
                @foreach(\App\Models\Campaigns\CampaignSuppression::REASONS as $v => $l)<option value="{{ $v }}" @selected(request('reason') === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <div><label class="cmp-label">Show</label>
            <select name="archived" class="cmp-input"><option value="">Suppressed</option><option value="1" @selected($lifted)>Lifted</option></select></div>
        <div><label class="cmp-label">From</label><input type="date" name="from" value="{{ request('from') }}" class="cmp-input"></div>
        <div><label class="cmp-label">To</label><input type="date" name="to" value="{{ request('to') }}" class="cmp-input"></div>
        <div class="flex gap-2">
            <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">
            <button class="corex-btn-primary text-xs flex-1 justify-center">Apply</button>
            <a href="{{ route('admin.campaigns.suppressions.index') }}" class="corex-btn-outline text-xs">Reset</a>
        </div>
    </form>

    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-3">
        <div class="cmp-panel overflow-x-auto">
            <table class="w-full ds-table" data-suppressions-table>
                <thead style="background: var(--surface-2);"><tr>
                    @include('admin.campaigns._sort', ['field' => 'email', 'label' => 'Email'])
                    @include('admin.campaigns._sort', ['field' => 'reason', 'label' => 'Reason'])
                    <th class="cmp-th">Campaign</th>
                    <th class="cmp-th">Note</th>
                    @include('admin.campaigns._sort', ['field' => 'when', 'label' => 'When'])
                    <th class="cmp-th"></th>
                </tr></thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr class="cmp-row" data-suppression-row="{{ $r->id }}">
                            <td class="cmp-td">{{ $r->email }}</td>
                            <td class="cmp-td">{{ \App\Models\Campaigns\CampaignSuppression::REASONS[$r->reason] ?? $r->reason }}</td>
                            <td class="cmp-td">@if($r->send?->campaign)<a class="cmp-link" href="{{ route('admin.campaigns.show', $r->send->campaign->id) }}">{{ $r->send->campaign->name }}</a>@else — @endif</td>
                            <td class="cmp-td cmp-muted">{{ $r->note ?: '—' }}</td>
                            <td class="cmp-td cmp-muted whitespace-nowrap">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="cmp-td text-right whitespace-nowrap">
                                @if($lifted)
                                    <form method="POST" action="{{ route('admin.campaigns.suppressions.restore', $r->id) }}">@csrf<button class="corex-btn-outline text-xs">Suppress again</button></form>
                                @else
                                    <form method="POST" action="{{ route('admin.campaigns.suppressions.archive', $r->id) }}" onsubmit="return confirm('Lift the suppression for {{ $r->email }}?')">@csrf @method('DELETE')<button class="corex-btn-outline text-xs">Lift</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-sm cmp-muted">
                            @if($filtered)
                                No addresses match these filters. <a href="{{ route('admin.campaigns.suppressions.index', $lifted ? ['archived' => 1] : []) }}" class="cmp-link">Clear filters</a>
                            @else
                                {{ $lifted ? 'No lifted suppressions.' : 'No suppressed addresses.' }}
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    </div>
</div>
@endsection
