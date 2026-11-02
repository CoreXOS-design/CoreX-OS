@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — Campaigns list (.ai/specs/campaigns.md §7.1). Header + tabs + filters frozen; only the table scrolls. --}}
<div class="w-full h-full flex flex-col">
    <div class="rounded-md px-6 py-3.5 corex-page-banner flex-shrink-0 flex items-center justify-between gap-3">
        <h1 class="text-base font-bold" style="color: var(--text-primary);">Campaigns</h1>
        <a href="{{ route('admin.campaigns.create') }}" class="corex-btn-primary text-xs">New campaign</a>
    </div>

    @include('admin.campaigns._head')

    @foreach($inboxProblems as $p)
        <div class="rounded-md px-4 py-2 mt-2 text-sm flex-shrink-0" data-inbox-problem="{{ $p->id }}"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
            Replies and bounces for {{ $p->email }} are not being checked — {{ (new \App\Services\Communications\MailFailureClassifier())->friendlyForConnect($p->last_inbox_error) }}
            <a href="{{ route('admin.campaigns.settings') }}" class="cmp-link ml-1">Settings</a>
        </div>
    @endforeach

    <form method="GET" class="cmp-panel p-3 mt-3 flex-shrink-0 grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
        <div class="col-span-2">
            <label class="cmp-label">Search</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Campaign or list name" class="cmp-input">
        </div>
        <div>
            <label class="cmp-label">Status</label>
            <select name="status" class="cmp-input">
                <option value="">All</option>
                @foreach(['draft' => 'Draft', 'running' => 'Running', 'paused' => 'Paused', 'done' => 'Done', 'archived' => 'Archived'] as $v => $l)
                    <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div><label class="cmp-label">Created from</label><input type="date" name="from" value="{{ request('from') }}" class="cmp-input"></div>
        <div><label class="cmp-label">Created to</label><input type="date" name="to" value="{{ request('to') }}" class="cmp-input"></div>
        <div class="flex gap-2">
            <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">
            <button class="corex-btn-primary text-xs flex-1 justify-center">Apply</button>
            <a href="{{ route('admin.campaigns.index') }}" class="corex-btn-outline text-xs">Reset</a>
        </div>
    </form>

    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-3">
        <div class="cmp-panel overflow-x-auto">
            <table class="w-full ds-table" data-campaigns-table>
                <thead style="background: var(--surface-2);">
                    <tr>
                        @include('admin.campaigns._sort', ['field' => 'name', 'label' => 'Name'])
                        @include('admin.campaigns._sort', ['field' => 'list', 'label' => 'List'])
                        @include('admin.campaigns._sort', ['field' => 'sender', 'label' => 'Mailbox'])
                        @include('admin.campaigns._sort', ['field' => 'steps', 'label' => 'Steps', 'align' => 'right'])
                        @include('admin.campaigns._sort', ['field' => 'sent', 'label' => 'Sent', 'align' => 'right'])
                        @include('admin.campaigns._sort', ['field' => 'replied', 'label' => 'Replied', 'align' => 'right'])
                        @include('admin.campaigns._sort', ['field' => 'stopped', 'label' => 'Stopped', 'align' => 'right'])
                        @include('admin.campaigns._sort', ['field' => 'status', 'label' => 'Status'])
                        <th class="cmp-th">Next run</th>
                        @include('admin.campaigns._sort', ['field' => 'created', 'label' => 'Created'])
                    </tr>
                </thead>
                <tbody>
                    @forelse($campaigns as $c)
                        <tr class="cmp-row cursor-pointer" data-campaign-row="{{ $c->id }}" onclick="window.location='{{ route('admin.campaigns.show', $c->id) }}'">
                            <td class="cmp-td font-medium"><a href="{{ route('admin.campaigns.show', $c->id) }}" class="cmp-link">{{ $c->name }}</a></td>
                            <td class="cmp-td">{{ $c->list_name ?? '—' }}</td>
                            <td class="cmp-td cmp-muted">{{ $c->sender_email ?? '—' }}</td>
                            <td class="cmp-td text-right">{{ $c->steps_count }}</td>
                            <td class="cmp-td text-right">{{ $c->sent_count }}</td>
                            <td class="cmp-td text-right">{{ $c->replied_count }}</td>
                            <td class="cmp-td text-right">{{ $c->stopped_count }}</td>
                            <td class="cmp-td">@include('admin.campaigns._status', ['status' => $c->trashed() ? 'archived' : $c->status])</td>
                            <td class="cmp-td cmp-muted whitespace-nowrap">{{ ($nextRuns[$c->id] ?? null)?->format('D d M H:i') ?? '—' }}</td>
                            <td class="cmp-td cmp-muted whitespace-nowrap">{{ $c->created_at?->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-3 py-10 text-center text-sm cmp-muted">
                            @if($filtered)
                                No campaigns match these filters. <a href="{{ route('admin.campaigns.index') }}" class="cmp-link">Clear filters</a>
                            @else
                                No campaigns yet. <a href="{{ route('admin.campaigns.create') }}" class="cmp-link">New campaign</a>
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $campaigns->links() }}</div>
    </div>
</div>
@endsection
