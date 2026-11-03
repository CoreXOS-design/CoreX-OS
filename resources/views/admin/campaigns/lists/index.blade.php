@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — Campaigns → Lists (.ai/specs/campaigns.md §7.3). --}}
<div class="w-full h-full flex flex-col" x-data="{ adding: {{ $errors->has('name') ? 'true' : 'false' }} }">
    <div class="rounded-md px-6 py-3.5 corex-page-banner flex-shrink-0 flex items-center justify-between gap-3">
        <h1 class="text-base font-bold" style="color: var(--text-primary);">Lists</h1>
        <button type="button" @click="adding = !adding" class="corex-btn-primary text-xs">New list</button>
    </div>

    @include('admin.campaigns._head')

    <form x-show="adding" x-cloak method="POST" action="{{ route('admin.campaigns.lists.store') }}" class="cmp-panel p-3 mt-2 flex-shrink-0 flex gap-2 items-end max-w-xl" data-new-list>
        @csrf
        <div class="flex-1"><label class="cmp-label" for="list-name">List name</label><input id="list-name" name="name" value="{{ old('name') }}" maxlength="150" required class="cmp-input"></div>
        <button class="corex-btn-primary text-xs">Create</button>
    </form>

    <form method="GET" class="cmp-panel p-3 mt-2 flex-shrink-0 grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
        <div class="col-span-2"><label class="cmp-label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="List name" class="cmp-input"></div>
        <div><label class="cmp-label">Show</label>
            <select name="archived" class="cmp-input"><option value="">Active</option><option value="1" @selected(request('archived') === '1')>Archived</option></select></div>
        <div><label class="cmp-label">Created from</label><input type="date" name="from" value="{{ request('from') }}" class="cmp-input"></div>
        <div><label class="cmp-label">Created to</label><input type="date" name="to" value="{{ request('to') }}" class="cmp-input"></div>
        <div class="flex gap-2">
            <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">
            <button class="corex-btn-primary text-xs flex-1 justify-center">Apply</button>
            <a href="{{ route('admin.campaigns.lists.index') }}" class="corex-btn-outline text-xs">Reset</a>
        </div>
    </form>

    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-3">
        <div class="cmp-panel overflow-x-auto">
            <table class="w-full ds-table" data-lists-table>
                <thead style="background: var(--surface-2);"><tr>
                    @include('admin.campaigns._sort', ['field' => 'name', 'label' => 'Name'])
                    @include('admin.campaigns._sort', ['field' => 'leads', 'label' => 'Leads', 'align' => 'right'])
                    <th class="cmp-th" style="text-align:right">New</th>
                    <th class="cmp-th" style="text-align:right">Sent</th>
                    <th class="cmp-th" style="text-align:right">Replied</th>
                    <th class="cmp-th" style="text-align:right">Stopped</th>
                    <th class="cmp-th">Campaign</th>
                    @include('admin.campaigns._sort', ['field' => 'imported', 'label' => 'Last import'])
                    @include('admin.campaigns._sort', ['field' => 'created', 'label' => 'Created'])
                </tr></thead>
                <tbody>
                    @forelse($lists as $l)
                        <tr class="cmp-row cursor-pointer" data-list-row="{{ $l->id }}" onclick="window.location='{{ route('admin.campaigns.lists.show', $l->id) }}'">
                            <td class="cmp-td font-medium"><a class="cmp-link" href="{{ route('admin.campaigns.lists.show', $l->id) }}">{{ $l->name }}</a></td>
                            <td class="cmp-td text-right">{{ $l->leads_count }}</td>
                            <td class="cmp-td text-right">{{ $l->new_count }}</td>
                            <td class="cmp-td text-right">{{ $l->sent_count }}</td>
                            <td class="cmp-td text-right">{{ $l->replied_count }}</td>
                            <td class="cmp-td text-right">{{ $l->stopped_count }}</td>
                            <td class="cmp-td">{{ $l->campaign?->name ?? '—' }}</td>
                            <td class="cmp-td cmp-muted whitespace-nowrap">{{ $l->last_imported_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="cmp-td cmp-muted whitespace-nowrap">{{ $l->created_at?->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-10 text-center text-sm cmp-muted">
                            @if($filtered)
                                No lists match these filters. <a href="{{ route('admin.campaigns.lists.index') }}" class="cmp-link">Clear filters</a>
                            @else
                                No lists yet. <button type="button" class="cmp-link" @click="adding = true">New list</button>
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $lists->links() }}</div>
    </div>
</div>
@endsection
