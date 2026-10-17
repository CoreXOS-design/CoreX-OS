@extends('layouts.corex')

{{--
    AT-451 — Users with every alert switched off (agency list).
    Reached from the compact "N users have all alerts off" line on the Rental Command Centre and the user list.

    BUILD_STANDARD §1b/§1c:
      Search   — name, email.
      Sort     — name, role, branch, "off since" (default: off since, longest first = the ones nobody has looked at longest).
      Filter   — status (all / not decided / accepted), branch, "off since" date range.
      Paging   — 25 per page.
      Scoping  — own / branch / agency IN THE QUERY (AlertSwitchService::scopedUsers, the user-management data scope);
                 permission manage_users; a direct URL for a user outside the viewer's scope answers 404.
      History  — every decision (accepted / turned on) is an append-only row, shown under each user.
    Deliberately no Alpine on this page: a plain GET filter form, POST buttons, native <details> for the history.
--}}

@php
    $sort = $filters['sort'] ?? 'since';
    $dir = $filters['dir'] ?? 'asc';
    $sortLink = fn ($col) => route('corex.alerts-off.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']
    ));
    $sortMark = fn ($col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $switchWords = fn ($d) => 'in-app ' . ($d->switch_in_app ? 'on' : 'off') . ', email ' . ($d->switch_email ? 'on' : 'off') . ', push ' . ($d->switch_push ? 'on' : 'off');
@endphp

@section('corex-content')
<div class="w-full space-y-4">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Users with all alerts off</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    These people will not be told about new faults, leads or approvals. Turn their in-app alerts back on, or accept that they stay off - either way it is kept on record.
                </p>
            </div>
            <a href="{{ route('admin.users') }}" class="corex-btn-outline text-xs">Back to users</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 14%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #16a34a) 40%, transparent);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 40%, transparent);">{{ session('error') }}</div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.alerts-off.index') }}" class="flex flex-wrap items-end gap-3" data-qa="alerts-off-filters">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or email" maxlength="100"
                   class="list-header-filter text-sm {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Decision</label>
            <select name="status" class="list-header-filter text-sm {{ ($filters['status'] ?? 'all') !== 'all' ? 'is-active' : '' }}">
                <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>All decisions</option>
                <option value="open" @selected(($filters['status'] ?? '') === 'open')>Not decided yet</option>
                <option value="accepted" @selected(($filters['status'] ?? '') === 'accepted')>Accepted - stays off</option>
            </select>
        </div>
        @if($branches->count() > 1)
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Branch</label>
            <select name="branch_id" class="list-header-filter text-sm {{ filled($filters['branch_id'] ?? '') ? 'is-active' : '' }}">
                <option value="">All branches</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected((int) ($filters['branch_id'] ?? 0) === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Off since - from</label>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['from'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">to</label>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['to'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Apply</button>
            @if($filtered)
                <a href="{{ route('corex.alerts-off.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
            @endif
        </div>
    </form>
    </div>

    <div class="rounded-md overflow-x-auto" style="border: 1px solid var(--border); background: var(--surface);">
        <table class="w-full text-sm" data-qa="alerts-off-table">
            <thead>
                <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide"><a href="{{ $sortLink('name') }}" style="color: var(--text-muted);">User{{ $sortMark('name') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide"><a href="{{ $sortLink('role') }}" style="color: var(--text-muted);">Role{{ $sortMark('role') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide"><a href="{{ $sortLink('branch') }}" style="color: var(--text-muted);">Branch{{ $sortMark('branch') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide"><a href="{{ $sortLink('since') }}" style="color: var(--text-muted);" title="When the notification settings were last saved">Off since{{ $sortMark('since') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Decision</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">What to do</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                @php $u = $row['user']; @endphp
                <tr style="border-bottom: 1px solid var(--border); vertical-align: top;" data-qa="alerts-off-row-{{ $u->id }}">
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $u->name }}</div>
                        <div class="text-xs" style="color: var(--text-muted);">{{ $u->email }}</div>
                    </td>
                    <td class="px-4 py-3">{{ ucwords(str_replace('_', ' ', (string) $u->role)) }}</td>
                    <td class="px-4 py-3">{{ $row['branch'] }}</td>
                    <td class="px-4 py-3">{{ $row['since'] ? $row['since']->format('j M Y') : '—' }}</td>
                    <td class="px-4 py-3">
                        @if($row['accepted'])
                            <span class="ds-badge ds-badge-muted">Accepted</span>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                by {{ $row['accepted_by_self'] ? 'the user' : 'an administrator' . ($row['accepted_by_name'] ? ' (' . $row['accepted_by_name'] . ')' : '') }}
                            </div>
                        @else
                            <span class="ds-badge ds-badge-warning">Not decided</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            @unless($row['locked'])
                                <form method="POST" action="{{ route('corex.alerts-off.turn-on', $u->id) }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="corex-btn-primary text-xs">Turn in-app alerts on</button>
                                </form>
                            @endunless
                            @unless($row['accepted'])
                                <form method="POST" action="{{ route('corex.alerts-off.accept', $u->id) }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs">Accept - no alerts for this user</button>
                                </form>
                            @endunless
                        </div>
                        @if($row['locked'])
                            <div class="text-xs mt-1" style="color: var(--text-muted);">Your agency sets the alert switches for everyone, so they cannot be turned on per person.</div>
                        @endif
                        @if($row['history']->isNotEmpty())
                            <details class="mt-2">
                                <summary class="text-xs cursor-pointer" style="color: var(--text-muted);">History ({{ $row['history']->count() }})</summary>
                                <ul class="text-xs mt-1 space-y-0.5" style="color: var(--text-secondary);">
                                    @foreach($row['history'] as $d)
                                        <li>
                                            {{ $d->created_at->format('j M Y H:i') }} -
                                            {{ $d->decision === 'accepted_off' ? 'accepted, stays off' : 'turned in-app alerts on' }}
                                            by {{ $d->decidedByUser() ? 'the user' : ($d->decidedBy?->name ?? 'an administrator') }}
                                            ({{ $switchWords($d) }})
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);" data-qa="alerts-off-empty">
                    @if($filtered)
                        No users match these filters. <a href="{{ route('corex.alerts-off.index') }}" style="color: var(--brand-icon, #0ea5e9);">Clear the filters</a> to see all {{ number_format($total) }}.
                    @else
                        Everyone you can see has at least one alert switched on.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</div>
@endsection
