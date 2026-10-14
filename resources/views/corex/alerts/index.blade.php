@extends('layouts.corex')

{{--
    The alert inbox (spec notification-system-overview.md section 16). Own alerts only - the query is pinned to the logged-in user.
    Search: title and body. Sort: newest (default) / oldest. Filter: type, unread / read / all, received from - to. Paging: 25.
    No delete: alerts are read or unread. Plain GET form + POST buttons, no Alpine.
--}}

@section('content')
<div class="p-6 space-y-4">
    @if(session('success'))
        <div class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 14%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #16a34a) 40%, transparent);">{{ session('success') }}</div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-2">
        <h1 class="text-lg font-semibold">Alerts</h1>
        @if($hasAny)
            <form method="POST" action="{{ route('corex.alerts.read-all') }}" class="m-0">
                @csrf
                <button type="submit" class="corex-btn-outline text-xs" data-qa="alerts-read-all">Mark all read</button>
            </form>
        @endif
    </div>

    <form method="GET" action="{{ route('corex.alerts.index') }}" class="flex flex-wrap items-end gap-3" data-qa="alerts-filters">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Title or text" maxlength="100"
                   class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
            <select name="type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border); max-width: 16rem;">
                <option value="">All</option>
                @foreach($types as $key => $label)
                    <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Show</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="all" @selected($filters['status'] === 'all')>All</option>
                <option value="unread" @selected($filters['status'] === 'unread')>Unread</option>
                <option value="read" @selected($filters['status'] === 'read')>Read</option>
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Received - from</label><br>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">to</label><br>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Sort</label><br>
            <select name="sort" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs">Apply</button>
            @if($filtered)
                <a href="{{ route('corex.alerts.index') }}" class="corex-btn-outline text-xs">Clear</a>
            @endif
        </div>
    </form>

    <div class="rounded-md overflow-x-auto" style="border: 1px solid var(--border); background: var(--surface);">
        <table class="w-full text-sm" data-qa="alerts-table">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2 w-8"></th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Alert</th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Type</th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Received</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $n)
                @php
                    $data = json_decode($n->data, true) ?: [];
                    $unread = $n->read_at === null;
                    $key = $data['event_key'] ?? null;
                    $title = $data['title'] ?? $data['message'] ?? ($key ? \Illuminate\Support\Str::headline(str_replace(['.', '_'], ' ', $key)) : \Illuminate\Support\Str::headline(class_basename($n->type)));
                    $received = \Carbon\Carbon::parse($n->created_at);
                @endphp
                <tr style="border-bottom: 1px solid var(--border); vertical-align: top;" data-qa="alert-row" data-read="{{ $unread ? '0' : '1' }}">
                    <td class="px-4 py-3">
                        <span class="inline-block w-2 h-2 rounded-full" title="{{ $unread ? 'Unread' : 'Read' }}" style="{{ $unread ? 'background:var(--brand-button);' : 'background:var(--text-muted);opacity:.35;' }}"></span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('corex.alerts.open', $n->id) }}" class="{{ $unread ? 'font-semibold' : 'font-medium' }}" style="color: var(--text-primary);">{{ $title }}</a>
                        @if(! empty($data['body']))
                            <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ \Illuminate\Support\Str::limit($data['body'], 240) }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $key ? ($labels[$key] ?? '') : '' }}</td>
                    <td class="px-4 py-3 text-xs whitespace-nowrap" style="color: var(--text-muted);" title="{{ $received->format('j M Y H:i') }}">{{ $received->diffForHumans() }}</td>
                    <td class="px-4 py-3 text-right">
                        @if($unread)
                            <form method="POST" action="{{ route('corex.alerts.read', $n->id) }}" class="m-0">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs">Mark read</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);" data-qa="alerts-empty">
                        {{ $hasAny ? 'No alerts match these filters.' : 'No alerts yet.' }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</div>
@endsection
