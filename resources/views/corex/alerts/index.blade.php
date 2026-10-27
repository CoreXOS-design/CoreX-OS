@extends('layouts.corex')

{{--
    The alert inbox (spec notification-system-overview.md section 16). Own alerts only - the query is pinned to the logged-in user.
    Search: title and body. Sort: newest (default) / oldest. Filter: type, unread / read / all, received from - to. Paging: 25.
    No delete: alerts are read or unread. Plain GET form + POST buttons, no Alpine.
--}}

@section('corex-content')
<div class="w-full space-y-4">
    @if(session('success'))
        <div class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 14%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #16a34a) 40%, transparent);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Notifications</h1>
                <p class="text-xs" style="color: var(--text-muted);">Every notification you have been sent - newest first, with what is still unread.</p>
            </div>
            @if($hasAny)
                <form method="POST" action="{{ route('corex.alerts.read-all') }}" class="m-0">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs" data-qa="alerts-read-all">Mark all read</button>
                </form>
            @endif
        </div>
    </div>

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.alerts.index') }}" class="flex flex-wrap items-end gap-3" data-qa="alerts-filters">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Title or text" maxlength="100"
                   class="list-header-filter text-sm {{ filled($filters['q']) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Type</label>
            <select name="type" class="list-header-filter text-sm {{ filled($filters['type']) ? 'is-active' : '' }}" style="max-width: 16rem;">
                <option value="">All types</option>
                @foreach($types as $key => $label)
                    <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Show</label>
            <select name="status" class="list-header-filter text-sm {{ ($filters['status'] ?? 'all') !== 'all' ? 'is-active' : '' }}">
                <option value="all" @selected($filters['status'] === 'all')>All notifications</option>
                <option value="unread" @selected($filters['status'] === 'unread')>Unread</option>
                <option value="read" @selected($filters['status'] === 'read')>Read</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Received - from</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="list-header-filter text-sm {{ filled($filters['from']) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">to</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="list-header-filter text-sm {{ filled($filters['to']) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Sort</label>
            <select name="sort" class="list-header-filter text-sm">
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Apply</button>
            @if($filtered)
                <a href="{{ route('corex.alerts.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
            @endif
        </div>
    </form>
    </div>

    <div class="rounded-md overflow-x-auto" style="border: 1px solid var(--border); background: var(--surface);">
        <table class="w-full text-sm" data-qa="alerts-table">
            <thead>
                <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-3 w-8"></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Notification</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Type</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Received</th>
                    <th class="px-4 py-3"></th>
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
                {{-- AT-491: the whole row opens the alert (the title link stays for keyboard users); Mark read keeps its own click. --}}
                <tr style="border-bottom: 1px solid var(--border); vertical-align: top; cursor: pointer;" class="hover:bg-[var(--surface-2)]" data-qa="alert-row" data-read="{{ $unread ? '0' : '1' }}"
                    data-href="{{ route('corex.alerts.open', $n->id) }}"
                    onclick="if (!event.target.closest('a, button, form')) { window.location.href = this.dataset.href; }">
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
                        {{ $hasAny ? 'No notifications match these filters.' : 'No notifications yet.' }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</div>
@endsection
